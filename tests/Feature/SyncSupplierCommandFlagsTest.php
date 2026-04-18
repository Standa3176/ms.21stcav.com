<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooProductIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * D-04 flag validation + resume-target existence checks.
 *
 * Each test stubs SupplierClient::fetchAllProducts + WooProductIterator::pages
 * so the command can complete without hitting any real HTTP endpoint.
 */
beforeEach(function () {
    Queue::fake();

    // Stub SupplierClient so fetchAllProducts returns an empty feed — no HTTP.
    $supplierStub = new class
    {
        public function fetchAllProducts(): array
        {
            return [];
        }
    };
    $this->app->instance(SupplierClient::class, $supplierStub);

    // Stub WooProductIterator to yield nothing.
    $iteratorStub = new class
    {
        public function pages(int $fromPage = 1): \Generator
        {
            if (false) {
                yield;
            }
        }
    };
    $this->app->instance(WooProductIterator::class, $iteratorStub);
});

// -----------------------------------------------------------------------------
// FL1: --live --dry-run exits non-zero with a clear error message
// -----------------------------------------------------------------------------
test('FL1: --live and --dry-run together exit non-zero with mutually-exclusive message', function () {
    $exitCode = $this->artisan('sync:supplier', ['--live' => true, '--dry-run' => true])
        ->expectsOutputToContain('mutually exclusive')
        ->run();

    expect($exitCode)->not->toBe(0);
});

// -----------------------------------------------------------------------------
// FL2: --resume=<non-existent-id> exits non-zero
// -----------------------------------------------------------------------------
test('FL2: --resume=<non-existent-id> exits non-zero', function () {
    $exitCode = $this->withoutExceptionHandling()
        ->artisan('sync:supplier', ['--resume' => 999_999]);

    try {
        $exitCode->run();
        $this->fail('Expected ModelNotFoundException');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        // Pass — findResumable throws when the id doesn't exist.
        expect(true)->toBeTrue();
    }
});

// -----------------------------------------------------------------------------
// FL3: No flags → exits 0, dry_run=true, SyncRun row created
// -----------------------------------------------------------------------------
test('FL3: no flags — exits 0, creates dry_run=true SyncRun', function () {
    $this->artisan('sync:supplier')
        ->assertSuccessful();

    $run = SyncRun::latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(SyncRun::STATUS_COMPLETED);
});

// -----------------------------------------------------------------------------
// FL4: --live → dry_run=false on the SyncRun
// -----------------------------------------------------------------------------
test('FL4: --live flag persists as dry_run=false on the SyncRun', function () {
    $this->artisan('sync:supplier', ['--live' => true])->assertSuccessful();

    $run = SyncRun::latest('id')->first();
    expect($run->dry_run)->toBeFalse();
});
