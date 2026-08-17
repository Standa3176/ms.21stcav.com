<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260809-uza PART 2 — supplier:db-sync stale-cost surfacing
|--------------------------------------------------------------------------
|
| A previously-costed product whose only suppliers are now excluded/stale/OOS
| matches no key in buildBestOfferMap. Neither --flag-obsolete (zero-offer only)
| nor products:flag-missing-buy-price (NULL only) catches it, so its stale
| buy_price silently keeps driving the sell-price recompute. supplier:db-sync
| now writes a STALE_COST_NO_SUPPLIER ImportIssue for it (cost NEVER changed).
|
| The stale-cost decision lives in perform()'s Product-iteration loop, driven by
| the mysqli remote pull (which the sibling SupplierDbSyncCommandTest deliberately
| does NOT mock — too fragile). Following that precedent, we exercise the extracted
| public seam surfaceStaleCostIssue() directly (mirrors buildBestOfferMap /
| isObsoleteCandidate being public for exactly this reason). It runs the REAL
| updateOrCreate write path, so a green case-1 also proves the new enum value
| inserts on SQLite — i.e. the driver-guarded migration's CHECK-constraint rebuild
| worked (the SQLite↔MariaDB strict trap).
*/

function makeStaleCostSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
    );
}

/** Seed a local product WITHOUT firing the ProductObserver echo-loop. */
function seedStaleCostProduct(array $overrides = []): Product
{
    return Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'woo_product_id' => 7001,
        'sku' => '9C941AA',
        'name' => 'Costed Product',
        'type' => 'simple',
        'status' => 'publish',
        'stock_status' => 'instock',
        'stock_quantity' => 3,
        'sell_price' => '1499.0000',
        'buy_price' => '1019.8900',
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
        'tags' => [],
    ], $overrides)));
}

it('writes a STALE_COST_NO_SUPPLIER issue for a costed product with no supplier offer; buy_price unchanged', function (): void {
    $p = seedStaleCostProduct();
    $cid = (string) Str::uuid();

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', $cid, dryRun: false);

    expect($result)->toBe('flagged');

    $issues = ImportIssue::where('issue_type', ImportIssue::STALE_COST_NO_SUPPLIER)->get();
    expect($issues)->toHaveCount(1);

    $issue = $issues->first();
    expect($issue->sku)->toBe('9C941AA')
        ->and((int) $issue->woo_product_id)->toBe(7001)
        ->and($issue->woo_variation_id)->toBeNull()
        ->and($issue->resolved_at)->toBeNull()
        ->and($issue->correlation_id)->toBe($cid)
        ->and($issue->detected_at)->not->toBeNull();

    // Cost is NEVER mutated by the surfacing.
    expect((string) $p->fresh()->buy_price)->toBe('1019.8900');
});

it('respects the is_custom_ms carve-out — no issue written', function (): void {
    $p = seedStaleCostProduct(['is_custom_ms' => true]);

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);

    expect($result)->toBe('skipped')
        ->and(ImportIssue::count())->toBe(0);
});

it('respects the exclude_from_auto_update carve-out — no issue written', function (): void {
    $p = seedStaleCostProduct(['exclude_from_auto_update' => true]);

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);

    expect($result)->toBe('skipped')
        ->and(ImportIssue::count())->toBe(0);
});

it('respects the custom-ms tag carve-out — no issue written', function (): void {
    $p = seedStaleCostProduct(['tags' => ['custom-ms']]);

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);

    expect($result)->toBe('skipped')
        ->and(ImportIssue::count())->toBe(0);
});

it('does NOT flag a NULL-cost product (that is the missing_cost_price / flag-missing-buy-price path)', function (): void {
    $p = seedStaleCostProduct(['buy_price' => null]);

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);

    expect($result)->toBe('skipped')
        ->and(ImportIssue::count())->toBe(0);
});

it('does NOT flag an empty-key product', function (): void {
    $p = seedStaleCostProduct();

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '', (string) Str::uuid(), dryRun: false);

    expect($result)->toBe('skipped')
        ->and(ImportIssue::count())->toBe(0);
});

it('is idempotent — a same-day re-run does not duplicate the unresolved row', function (): void {
    $p = seedStaleCostProduct();
    $cmd = makeStaleCostSyncCommand();

    $cmd->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);
    $cmd->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: false);

    expect(ImportIssue::where('issue_type', ImportIssue::STALE_COST_NO_SUPPLIER)->count())->toBe(1);
});

it('--dry-run writes NO issue row but reports a would-flag', function (): void {
    $p = seedStaleCostProduct();

    $result = makeStaleCostSyncCommand()->surfaceStaleCostIssue($p, '9c941aa', (string) Str::uuid(), dryRun: true);

    expect($result)->toBe('would-flag')
        ->and(ImportIssue::count())->toBe(0);
});
