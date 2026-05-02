<?php

declare(strict_types=1);

use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Build a Local-adapter "remote" filesystem in a temp dir.
    $this->fakeRemoteRoot = storage_path('framework/testing/ftp-pull-dryrun-'.uniqid());
    File::ensureDirectoryExists($this->fakeRemoteRoot);

    $remoteFs = new Filesystem(new LocalFilesystemAdapter($this->fakeRemoteRoot));
    $remoteFs->write('cisco_2026-05-02.csv', "sku,price\nABC123,99.99\n");

    // Bind a stub connector that returns our local-backed Flysystem.
    $stub = new class($remoteFs) extends FtpSourceConnector
    {
        public function __construct(private readonly Filesystem $fs) {}

        public function connect(CompetitorFtpSource $source): Filesystem
        {
            return $this->fs;
        }
    };

    $this->app->instance(FtpSourceConnector::class, $stub);

    // Clear the real incoming/ dir for the assertion.
    $incoming = storage_path('app/competitors/incoming');
    if (is_dir($incoming)) {
        foreach (glob($incoming.'/*') ?: [] as $f) {
            @unlink($f);
        }
    }
});

afterEach(function (): void {
    if (isset($this->fakeRemoteRoot) && is_dir($this->fakeRemoteRoot)) {
        File::deleteDirectory($this->fakeRemoteRoot);
    }
});

it('runs in dry-run by default — no files written, no source state mutated', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'cisco']);
    $source = CompetitorFtpSource::factory()->create(['competitor_id' => $competitor->id]);

    $exitCode = Artisan::call('competitor:ftp-pull');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('DRY-RUN')
        ->and($output)->toContain('[DRY-RUN] would fetch cisco_2026-05-02.csv');

    // Source state untouched in dry-run.
    $source->refresh();
    expect($source->last_pulled_at)->toBeNull()
        ->and($source->last_pull_files_fetched)->toBe(0)
        ->and($source->consecutive_failures)->toBe(0);

    // No file written into incoming/.
    $incoming = storage_path('app/competitors/incoming');
    $writtenFiles = is_dir($incoming) ? glob($incoming.'/*.csv') : [];
    expect($writtenFiles)->toBe([]);
});
