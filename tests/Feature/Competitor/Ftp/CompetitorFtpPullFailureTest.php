<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Ftp\Notifications\CompetitorFtpPullFailedNotification;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use League\Flysystem\Filesystem;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Helper — bind a connector that throws on every connect() call.
 */
function bindFailingConnector(string $errorMessage): void
{
    $stub = new class($errorMessage) extends FtpSourceConnector
    {
        public function __construct(private readonly string $msg) {}

        public function connect(CompetitorFtpSource $source): Filesystem
        {
            throw new RuntimeException($this->msg);
        }
    };
    app()->instance(FtpSourceConnector::class, $stub);
}

it('increments consecutive_failures and writes a csv_parse_errors row on per-source exception (D-10)', function (): void {
    bindFailingConnector('Connection refused on port 22');

    $competitor = Competitor::factory()->create();
    $source = CompetitorFtpSource::factory()->create([
        'competitor_id' => $competitor->id,
        'host' => 'broken.example.com',
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    $source->refresh();
    expect($source->consecutive_failures)->toBe(1)
        ->and($source->last_pull_status)->toBe(CompetitorFtpSource::STATUS_FAILED)
        ->and($source->last_pull_error)->toContain('Connection refused')
        ->and($source->is_active)->toBeTrue(); // 1 < threshold (3)

    $err = CsvParseError::where('issue_type', CsvParseError::TYPE_FTP_PULL_FAILED)->first();
    expect($err)->not->toBeNull();
    $context = $err->context;
    expect($context['source_id'] ?? null)->toBe($source->id)
        ->and($context['host'] ?? null)->toBe('broken.example.com')
        ->and($context['error'] ?? null)->toContain('Connection refused');
});

it('auto-disables and notifies recipients after 3rd consecutive failure (D-12)', function (): void {
    Notification::fake();
    bindFailingConnector('Auth failed');

    $competitor = Competitor::factory()->create();
    $source = CompetitorFtpSource::factory()->nearAutoDisable()->create([
        'competitor_id' => $competitor->id,
    ]); // consecutive_failures = 2

    $recipient = AlertRecipient::create([
        'email' => 'comp-ftp-ops@example.com',
        'is_active' => true,
        'receives_competitor_ftp_alerts' => true,
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    $source->refresh();
    expect($source->consecutive_failures)->toBe(3)
        ->and($source->is_active)->toBeFalse();

    Notification::assertSentTo($recipient, CompetitorFtpPullFailedNotification::class);

    // Audit row for the auto-disable event.
    $audit = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'competitor_ftp_pull_disabled')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->properties->toArray()['source_id'] ?? null)->toBe($source->id);
});

it('does NOT notify recipients with receives_competitor_ftp_alerts=false', function (): void {
    Notification::fake();
    bindFailingConnector('TLS handshake failure');

    $competitor = Competitor::factory()->create();
    CompetitorFtpSource::factory()->nearAutoDisable()->create(['competitor_id' => $competitor->id]);

    $recipient = AlertRecipient::create([
        'email' => 'opted-out@example.com',
        'is_active' => true,
        'receives_competitor_ftp_alerts' => false,
    ]);

    Artisan::call('competitor:ftp-pull', ['--live' => true]);

    Notification::assertNotSentTo($recipient, CompetitorFtpPullFailedNotification::class);
});
