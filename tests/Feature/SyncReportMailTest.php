<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Domain\Sync\Mail\SupplierSyncReportMail;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Reports\SyncReportCsvGenerator;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooProductIterator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());

    $dir = storage_path('app/private/sync-reports');
    if (is_dir($dir)) {
        File::cleanDirectory($dir);
    }
});

function stubSupplierClientEmpty(): void
{
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchAllProducts')->andReturn([]);
    app()->instance(SupplierClient::class, $supplier);

    app()->instance(WooProductIterator::class, new class
    {
        public function pages(int $fromPage = 1): \Generator
        {
            if (false) {
                yield [];
            }
        }
    });
}

function stubSupplierClientThrowingJwt(): void
{
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchAllProducts')->andThrow(new JwtRefreshFailedException('bad creds'));
    app()->instance(SupplierClient::class, $supplier);

    app()->instance(WooProductIterator::class, new class
    {
        public function pages(int $fromPage = 1): \Generator
        {
            if (false) {
                yield [];
            }
        }
    });
}

it('builds a completed-run envelope with the updated_count subject', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => Context::get('correlation_id'),
        'updated_count' => 42,
    ]);

    $mail = new SupplierSyncReportMail($run, '/tmp/fake.csv', aborted: false);
    $envelope = $mail->envelope();

    expect($envelope->subject)->toBe("Supplier sync {$run->id} — 42 updated");
});

it('builds an aborted-run envelope with [ABORTED] prefix + abort_reason', function (): void {
    $run = SyncRun::factory()->aborted()->create([
        'correlation_id' => Context::get('correlation_id'),
        'abort_reason' => 'consecutive_failures',
        'abort_message' => '50 consecutive failures',
    ]);

    $mail = new SupplierSyncReportMail($run, '/tmp/fake.csv', aborted: true);
    $envelope = $mail->envelope();

    expect($envelope->subject)
        ->toContain('[ABORTED]')
        ->toContain('consecutive_failures');
});

it('attaches the CSV with the per-run filename and text/csv MIME', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => Context::get('correlation_id'),
    ]);

    $path = app(SyncReportCsvGenerator::class)->generate($run);

    $mail = new SupplierSyncReportMail($run, $path, aborted: false);
    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1);
    $attachment = $attachments[0];
    expect($attachment->as)->toBe("supplier-sync-run-{$run->id}.csv");
    expect($attachment->mime)->toBe('text/csv');
});

it('SyncSupplierCommand emails completed runs to active opted-in recipients only', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'active-opted-in@example.test',
        'is_active' => true,
        'receives_sync_reports' => true,
    ]);
    AlertRecipient::create([
        'email' => 'active-opted-out@example.test',
        'is_active' => true,
        'receives_sync_reports' => false,
    ]);
    AlertRecipient::create([
        'email' => 'inactive@example.test',
        'is_active' => false,
        'receives_sync_reports' => true,
    ]);

    stubSupplierClientEmpty();

    $exitCode = $this->artisan('sync:supplier')->run();
    expect($exitCode)->toBe(0);

    Mail::assertSent(SupplierSyncReportMail::class, function ($mail): bool {
        // $mail->to is an array of ['address' => ..., 'name' => ...] entries.
        $to = collect($mail->to)->pluck('address')->all();

        return $to === ['active-opted-in@example.test'];
    });
    Mail::assertSent(SupplierSyncReportMail::class, 1);
});

it('SyncSupplierCommand emails aborted runs too (JwtRefreshFailedException path)', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'ops@example.test',
        'is_active' => true,
        'receives_sync_reports' => true,
    ]);

    stubSupplierClientThrowingJwt();

    $exitCode = $this->artisan('sync:supplier')->run();
    expect($exitCode)->toBe(1);

    Mail::assertSent(SupplierSyncReportMail::class, function ($mail): bool {
        return $mail->aborted === true;
    });
});

it('SyncSupplierCommand warns but does not fail when no recipients are opted-in', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'opted-out@example.test',
        'is_active' => true,
        'receives_sync_reports' => false,
    ]);

    stubSupplierClientEmpty();

    $exitCode = $this->artisan('sync:supplier')->run();
    expect($exitCode)->toBe(0);
    Mail::assertNothingSent();
});
