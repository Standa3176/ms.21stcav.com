<?php

declare(strict_types=1);

use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Fake "remote" via Local adapter rooted in a temp dir.
    $this->fakeRemoteRoot = storage_path('framework/testing/ftp-pull-live-'.uniqid());
    File::ensureDirectoryExists($this->fakeRemoteRoot);

    $remoteFs = new Filesystem(new LocalFilesystemAdapter($this->fakeRemoteRoot));
    $remoteFs->write('cisco_2026-05-02.csv', "sku,price\nABC123,99.99\n");
    $remoteFs->write('garbage.txt', 'not a csv');

    $stub = new class($remoteFs) extends FtpSourceConnector
    {
        public function __construct(private readonly Filesystem $fs) {}

        public function connect(CompetitorFtpSource $source): Filesystem
        {
            return $this->fs;
        }
    };
    $this->app->instance(FtpSourceConnector::class, $stub);

    // Clear actual incoming/.
    $this->incomingDir = storage_path('app/competitors/incoming');
    if (is_dir($this->incomingDir)) {
        foreach (glob($this->incomingDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
    }
});

afterEach(function (): void {
    if (isset($this->fakeRemoteRoot) && is_dir($this->fakeRemoteRoot)) {
        File::deleteDirectory($this->fakeRemoteRoot);
    }
    if (isset($this->incomingDir) && is_dir($this->incomingDir)) {
        foreach (glob($this->incomingDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
    }
});

it('--live downloads matching files into incoming/ and updates source state', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'cisco']);
    $source = CompetitorFtpSource::factory()->create(['competitor_id' => $competitor->id]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    // CSV landed; non-matching .txt skipped.
    expect(file_exists($this->incomingDir.'/cisco_2026-05-02.csv'))->toBeTrue();
    expect(file_exists($this->incomingDir.'/garbage.txt'))->toBeFalse();

    // Source state updated.
    $source->refresh();
    expect($source->last_pull_status)->toBe(CompetitorFtpSource::STATUS_SUCCESS)
        ->and($source->last_pull_files_fetched)->toBe(1)
        ->and($source->consecutive_failures)->toBe(0)
        ->and($source->last_pulled_at)->not->toBeNull();

    // Audit row written by Auditor::record('competitor_ftp_pull', ...).
    $audit = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'competitor_ftp_pull')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties->toArray()['mode'] ?? null)->toBe('live');
});

it('writes via .tmp atomic rename — no .tmp files remain after run', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'cisco']);
    CompetitorFtpSource::factory()->create(['competitor_id' => $competitor->id]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    $tmpFiles = glob($this->incomingDir.'/*.tmp') ?: [];
    expect($tmpFiles)->toBe([]);
});

it('skips files older than last_pulled_at (mtime gate)', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'cisco']);

    // Pre-set last_pulled_at to "now+1h" so the remote file's mtime is older.
    $source = CompetitorFtpSource::factory()->create([
        'competitor_id' => $competitor->id,
        'last_pulled_at' => now()->addHour(),
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    expect(file_exists($this->incomingDir.'/cisco_2026-05-02.csv'))->toBeFalse();

    $source->refresh();
    // 0 files fetched → status=partial (>0 fetched would have been success).
    expect($source->last_pull_files_fetched)->toBe(0)
        ->and($source->last_pull_status)->toBe(CompetitorFtpSource::STATUS_PARTIAL);
});
