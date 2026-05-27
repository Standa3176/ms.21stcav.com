<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\SourcingGapScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierFeedSourceabilityChecker;

/*
|--------------------------------------------------------------------------
| SourcingGapScanner — competitor lists it, NO supplier carries it, we don't sell it
|--------------------------------------------------------------------------
| The remote supplier-feed read is stubbed (no live DB) by substituting a
| SupplierFeedSourceabilityChecker that returns a fixed sourceable key set.
*/

/** Build a checker double whose sourceableKeys() returns only the given keys. */
function fakeSourceabilityChecker(array $sourceable): SupplierFeedSourceabilityChecker
{
    $set = [];
    foreach ($sourceable as $k) {
        $set[strtolower(trim($k))] = true;
    }

    return new class($set) extends SupplierFeedSourceabilityChecker
    {
        /** @param array<string, true> $set */
        public function __construct(private array $set) {}

        public function sourceableKeys(array $keys): array
        {
            $out = [];
            foreach ($keys as $k) {
                $k = strtolower(trim((string) $k));
                if ($k !== '' && isset($this->set[$k])) {
                    $out[$k] = true;
                }
            }

            return $out;
        }
    };
}

it('flags a competitor-only part that no supplier carries as a sourcing gap', function (): void {
    $rival = Competitor::factory()->create(['name' => 'RivalCo']);
    CompetitorPrice::factory()->forSku('GHOST-1')
        ->create(['competitor_id' => $rival->id, 'price_pennies_ex_vat' => 7000]);

    $scan = (new SourcingGapScanner(fakeSourceabilityChecker([])))->compute();

    expect($scan['count'])->toBe(1)
        ->and($scan['gaps'][0]['part'])->toBe('GHOST-1')
        ->and($scan['gaps'][0]['competitors'])->toBe(1)
        ->and($scan['gaps'][0]['comp_ex'])->toBe(7000)
        ->and($scan['gaps'][0]['competitor_name'])->toBe('RivalCo');
});

it('excludes a competitor part a supplier carries (add opportunity, not a gap)', function (): void {
    CompetitorPrice::factory()->forSku('SOURCEABLE-1')->create(['price_pennies_ex_vat' => 5000]);

    // Supplier feed DOES carry it → not a sourcing gap.
    $scan = (new SourcingGapScanner(fakeSourceabilityChecker(['sourceable-1'])))->compute();

    expect($scan['count'])->toBe(0);
});

it('excludes a part we already sell, by sku or by mpn', function (): void {
    // We sell SELL-1 → not a gap even though no supplier offer + competitor lists it.
    Product::factory()->create(['type' => 'simple', 'sku' => 'SELL-1']);
    CompetitorPrice::factory()->forSku('SELL-1')->create(['price_pennies_ex_vat' => 6000]);

    // We sell PROD-9; competitor lists it under COMP-X but its mpn = PROD-9 → excluded.
    Product::factory()->create(['type' => 'simple', 'sku' => 'PROD-9']);
    CompetitorPrice::factory()->create(['sku' => 'COMP-X', 'mpn' => 'PROD-9', 'price_pennies_ex_vat' => 6000]);

    $scan = (new SourcingGapScanner(fakeSourceabilityChecker([])))->compute();

    expect($scan['count'])->toBe(0);
});

it('counts distinct competitors and reports the lowest current ex-VAT + its competitor', function (): void {
    $cheap = Competitor::factory()->create(['name' => 'Cheapest']);
    $dear = Competitor::factory()->create(['name' => 'Dearest']);

    CompetitorPrice::factory()->forSku('MULTI-1')
        ->create(['competitor_id' => $dear->id, 'price_pennies_ex_vat' => 9000]);
    CompetitorPrice::factory()->forSku('MULTI-1')
        ->create(['competitor_id' => $cheap->id, 'price_pennies_ex_vat' => 8000]);

    $scan = (new SourcingGapScanner(fakeSourceabilityChecker([])))->compute();

    expect($scan['count'])->toBe(1)
        ->and($scan['gaps'][0]['competitors'])->toBe(2)
        ->and($scan['gaps'][0]['comp_ex'])->toBe(8000)
        ->and($scan['gaps'][0]['competitor_name'])->toBe('Cheapest');
});

it('ignores competitor prices outside the recency window', function (): void {
    CompetitorPrice::factory()->forSku('STALE-1')->recordedAt(now()->subDays(60))
        ->create(['price_pennies_ex_vat' => 7000]);

    $scan = (new SourcingGapScanner(fakeSourceabilityChecker([])))->compute(maxAgeDays: 30);

    expect($scan['count'])->toBe(0);
});

it('checker short-circuits empty / whitespace-only keys without a DB connection', function (): void {
    // The real checker would connect to the supplier DB; an empty/blank key set
    // must return [] before any connection (guards the schedule from no-op scans).
    $checker = app(SupplierFeedSourceabilityChecker::class);

    expect($checker->sourceableKeys([]))->toBe([])
        ->and($checker->sourceableKeys(['', '   ']))->toBe([]);
});
