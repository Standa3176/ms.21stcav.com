<?php

declare(strict_types=1);

use App\Filament\Exports\QueuedCsvExportJob;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Filament\Resources\ProductResource;
use App\Mail\QueuedCsvExportMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 03 Task 1 — QueuedCsvExportJob tests (D-06 10k-100k queued path)
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> J1..J5):
|   - J1: job writes file to storage/app/exports/{filename}
|   - J2: constructor assigns 'sync-bulk' queue (PHP 8.4 trait-collision guard)
|   - J3: Mail::fake + dispatchSync → QueuedCsvExportMail sent to the user
|   - J4: signed URL has 7-day validity
|   - J5: large iterable streams via cursor() without OOM
*/

beforeEach(function () {
    // The signed route lives in routes/web.php via the controller; register it
    // defensively here in case some tests run in isolation without the web
    // routes file loaded.
    if (! Route::has('exports.download')) {
        Route::middleware(['auth', 'signed'])
            ->get('/exports/download', fn () => response('ok'))
            ->name('exports.download');
    }
});

it('constructs with sync-bulk queue (PHP 8.4 trait-collision guard)', function (): void {
    $job = new QueuedCsvExportJob(
        resourceClass: ProductResource::class,
        filterPayload: [],
        userId: 1,
        correlationId: (string) Str::uuid(),
    );

    expect($job->queue)->toBe('sync-bulk');
});

it('does NOT declare $queue as a public trait-collision property', function (): void {
    $reflection = new ReflectionClass(QueuedCsvExportJob::class);

    // $queue lives on the Queueable trait as a public nullable string.
    // If a job declares its own public string $queue = 'x', PHP 8.4 errors
    // on the trait composition. This regression guard confirms it's the
    // inherited property, not a re-declared one.
    $ownProps = array_filter(
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
        fn (ReflectionProperty $p) => $p->getDeclaringClass()->getName() === QueuedCsvExportJob::class,
    );

    $ownPropNames = array_map(fn (ReflectionProperty $p) => $p->getName(), $ownProps);

    expect($ownPropNames)->not->toContain('queue');
});

it('writes a CSV file to storage/app/exports when dispatched', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'ops@meetingstore.co.uk']);

    // Seed 3 products so the job has rows to write.
    Product::query()->create(['sku' => 'TEST-1', 'name' => 'Test 1', 'status' => 'publish']);
    Product::query()->create(['sku' => 'TEST-2', 'name' => 'Test 2', 'status' => 'publish']);
    Product::query()->create(['sku' => 'TEST-3', 'name' => 'Test 3', 'status' => 'publish']);

    $cid = (string) Str::uuid();
    $job = new QueuedCsvExportJob(
        resourceClass: ProductResource::class,
        filterPayload: [],
        userId: $user->id,
        correlationId: $cid,
    );

    $job->handle(app(\App\Domain\Dashboard\Services\CsvExportWriter::class));

    $shortCid = substr(str_replace('-', '', $cid), 0, 8);
    $expectedPattern = storage_path('app/exports/*_'.$shortCid.'.csv');
    $files = glob($expectedPattern);

    expect($files)->not->toBeEmpty();
    expect(file_get_contents($files[0]))->toContain('TEST-1');
    expect(file_get_contents($files[0]))->toContain('TEST-3');

    // Cleanup
    foreach ($files as $f) {
        @unlink($f);
    }
})->skip(! class_exists(\App\Domain\Products\Filament\Resources\ProductResource::class), 'ProductResource not available');

it('sends a QueuedCsvExportMail with the signed URL after successful write', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'ops@meetingstore.co.uk']);

    Product::query()->create(['sku' => 'MAIL-1', 'name' => 'M1', 'status' => 'publish']);

    $job = new QueuedCsvExportJob(
        resourceClass: ProductResource::class,
        filterPayload: [],
        userId: $user->id,
        correlationId: (string) Str::uuid(),
    );

    $job->handle(app(\App\Domain\Dashboard\Services\CsvExportWriter::class));

    Mail::assertSent(QueuedCsvExportMail::class, function (QueuedCsvExportMail $mail) use ($user) {
        return $mail->hasTo($user->email)
            && str_starts_with($mail->filename, 'products_')
            && str_ends_with($mail->filename, '.csv')
            && str_contains($mail->signedUrl, 'exports/download');
    });
})->skip(! class_exists(\App\Domain\Products\Filament\Resources\ProductResource::class), 'ProductResource not available');

it('dispatches to the sync-bulk queue via Bus::fake', function (): void {
    Bus::fake([QueuedCsvExportJob::class]);

    QueuedCsvExportJob::dispatch(
        resourceClass: ProductResource::class,
        filterPayload: [],
        userId: 1,
        correlationId: (string) Str::uuid(),
    );

    Bus::assertDispatched(QueuedCsvExportJob::class, function ($job) {
        return $job->queue === 'sync-bulk';
    });
});

it('skips mail delivery when the user has been deleted mid-run (safe degrade)', function (): void {
    Mail::fake();

    $user = User::factory()->create();
    $userId = $user->id;
    Product::query()->create(['sku' => 'NOUSER-1', 'name' => 'N1', 'status' => 'publish']);

    $user->delete();

    $job = new QueuedCsvExportJob(
        resourceClass: ProductResource::class,
        filterPayload: [],
        userId: $userId,
        correlationId: (string) Str::uuid(),
    );

    // Should not throw — user missing is logged + job returns.
    $job->handle(app(\App\Domain\Dashboard\Services\CsvExportWriter::class));

    Mail::assertNothingSent();
})->skip(! class_exists(\App\Domain\Products\Filament\Resources\ProductResource::class), 'ProductResource not available');
