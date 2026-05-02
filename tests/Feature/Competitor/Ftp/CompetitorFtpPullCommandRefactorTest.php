<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Ftp\Notifications\CompetitorFtpPullFailedNotification;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.2 Plan 01 Task 2 — CompetitorFtpPullCommand refactor tests.
|--------------------------------------------------------------------------
|
| Asserts: (a) iterates feeds (not sources), (b) --feed flag scopes correctly,
| (c) --credential flag scopes correctly, (d) mutual exclusion enforced,
| (e) skip-on-no-change gate (D-13 step 2), (f) tsv → csv normalisation,
| (g) 3-strike auto-disable + notification, (h) dry-run default writes nothing.
*/

beforeEach(function (): void {
    // Fake "remote" via Local Flysystem rooted in a temp dir.
    $this->fakeRemoteRoot = storage_path('framework/testing/ftp-pull-refactor-'.uniqid());
    File::ensureDirectoryExists($this->fakeRemoteRoot);
    $this->remoteFs = new Filesystem(new LocalFilesystemAdapter($this->fakeRemoteRoot));

    // Stub the connector — every connect() returns the SAME local Filesystem.
    $remoteFs = $this->remoteFs;
    $stub = new class($remoteFs) extends FtpSourceConnector
    {
        public int $connectCalls = 0;

        public array $connectedCredentialIds = [];

        public function __construct(private readonly Filesystem $fs) {}

        public function connect(CompetitorFtpCredential $credential): Filesystem
        {
            $this->connectCalls++;
            $this->connectedCredentialIds[] = $credential->id;

            return $this->fs;
        }
    };
    $this->stubConnector = $stub;
    $this->app->instance(FtpSourceConnector::class, $stub);

    // Reset incoming/.
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

it('iterates active feeds (not sources) — only active rows pulled', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();

    $this->remoteFs->write('a.csv', "x,y\n1,2\n");
    $this->remoteFs->write('b.csv', "x,y\n3,4\n");
    $this->remoteFs->write('c.csv', "x,y\n5,6\n");

    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'a.csv', 'local_filename' => 'a.csv', 'is_active' => true,
    ]);
    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'b.csv', 'local_filename' => 'b.csv', 'is_active' => true,
    ]);
    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'c.csv', 'local_filename' => 'c.csv', 'is_active' => false,
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    expect($this->stubConnector->connectCalls)->toBe(2);
});

it('--feed={id} pulls only that feed', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $this->remoteFs->write('a.csv', "x,y\n1,2\n");
    $this->remoteFs->write('b.csv', "x,y\n3,4\n");

    $feedA = CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'a.csv', 'local_filename' => 'a.csv',
    ]);
    $feedB = CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'b.csv', 'local_filename' => 'b.csv',
    ]);

    Artisan::call('competitor:ftp-pull', ['--feed' => $feedB->id, '--live' => true]);

    expect($this->stubConnector->connectCalls)->toBe(1);
    expect(file_exists($this->incomingDir.'/b.csv'))->toBeTrue();
    expect(file_exists($this->incomingDir.'/a.csv'))->toBeFalse();
});

it('--credential={ulid} pulls all feeds for that credential', function (): void {
    $cred1 = CompetitorFtpCredential::factory()->create();
    $cred2 = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $this->remoteFs->write('a.csv', "x,y\n");
    $this->remoteFs->write('b.csv', "x,y\n");
    $this->remoteFs->write('c.csv', "x,y\n");

    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred1->id,
        'remote_filename' => 'a.csv', 'local_filename' => 'a.csv',
    ]);
    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred1->id,
        'remote_filename' => 'b.csv', 'local_filename' => 'b.csv',
    ]);
    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred2->id,
        'remote_filename' => 'c.csv', 'local_filename' => 'c.csv',
    ]);

    Artisan::call('competitor:ftp-pull', ['--credential' => $cred1->id, '--live' => true]);

    expect($this->stubConnector->connectCalls)->toBe(2);
    foreach ($this->stubConnector->connectedCredentialIds as $id) {
        expect($id)->toBe($cred1->id);
    }
});

it('errors when --feed and --credential both passed', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $exitCode = Artisan::call('competitor:ftp-pull', [
        '--feed' => 1,
        '--credential' => $cred->id,
        '--live' => true,
    ]);

    expect($exitCode)->not->toBe(0);
});

it('skips when remote mtime equals stored remote_file_date (no_change)', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $this->remoteFs->write('a.csv', "x,y\n1,2\n");
    $remoteMtime = $this->remoteFs->lastModified('a.csv');

    $feed = CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'a.csv', 'local_filename' => 'a.csv',
        'remote_file_date' => \Illuminate\Support\Carbon::createFromTimestamp($remoteMtime),
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    $feed->refresh();
    expect($feed->last_pull_status)->toBe(CompetitorFtpFeed::STATUS_NO_CHANGE);
    expect(file_exists($this->incomingDir.'/a.csv'))->toBeFalse();
});

it('normalises tsv → csv before writing to incoming/', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $this->remoteFs->write('feed.tsv', "a\tb\n1\t2\n");

    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'feed.tsv', 'local_filename' => 'nuvias.csv',
        'format' => CompetitorFtpFeed::FORMAT_TSV,
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    expect(file_exists($this->incomingDir.'/nuvias.csv'))->toBeTrue();
    $written = file_get_contents($this->incomingDir.'/nuvias.csv');
    expect($written)->toContain('a,b');
    expect($written)->not->toContain("\t");
});

it('auto-disables a feed after 3 consecutive failures + notifies recipients', function (): void {
    Notification::fake();

    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $feed = CompetitorFtpFeed::factory()->nearAutoDisable()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'never-here.csv', 'local_filename' => 'feed.csv',
    ]);
    AlertRecipient::create([
        'email' => 'ops-ftp@example.com',
        'name' => 'Ops FTP',
        'is_active' => true,
        'receives_competitor_ftp_alerts' => true,
    ]);

    // remote file does NOT exist on the fake; lastModified() will throw.
    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    $feed->refresh();
    expect($feed->consecutive_failures)->toBe(3);
    expect($feed->is_active)->toBeFalse();
    expect($feed->last_pull_status)->toBe(CompetitorFtpFeed::STATUS_FAILED);

    Notification::assertSentTo(
        AlertRecipient::all(),
        CompetitorFtpPullFailedNotification::class
    );

    expect(CsvParseError::query()->where('issue_type', 'ftp_pull_failed')->count())->toBeGreaterThan(0);
});

it('dry-run default writes nothing into incoming/', function (): void {
    $cred = CompetitorFtpCredential::factory()->create();
    $competitor = Competitor::factory()->create();
    $this->remoteFs->write('a.csv', "x,y\n1,2\n");

    CompetitorFtpFeed::factory()->create([
        'competitor_id' => $competitor->id, 'credential_id' => $cred->id,
        'remote_filename' => 'a.csv', 'local_filename' => 'a.csv',
    ]);

    Artisan::call('competitor:ftp-pull'); // default dry-run

    expect(file_exists($this->incomingDir.'/a.csv'))->toBeFalse();
});
