<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;

/*
|--------------------------------------------------------------------------
| Phase 3 Ship Gate — Golden-fixture parity test (PRCE-06, D-04).
|--------------------------------------------------------------------------
|
| For each of 50 (supplier_pennies, margin_basis_points, vat_basis_points)
| triples in tests/Fixtures/Pricing/golden-fixtures.json, the PriceCalculator
| MUST return exactly expected_final_pennies. Any drift by a single penny
| fails the build. This is success criterion #1 of Phase 3.
|
| The fixture is deterministic-v1 (42 tier triples + 8 edge cases). When ops
| re-baselines from a live Woo DB snapshot, the fixture's `source` tag flips
| to live-woo-snapshot-YYYY-MM-DD and the default tier seeder margins change
| in the SAME commit (D-04).
*/

/**
 * Load the 50 golden triples once at module level. Pest evaluates dataset
 * closures during test discovery; loading JSON via base_path() inside the
 * closure can trip an internal Pest initialization error on some setups.
 * Loading eagerly here sidesteps that.
 */
function goldenFixtures(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = __DIR__.'/../../Fixtures/Pricing/golden-fixtures.json';
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read golden fixtures: {$path}");
    }

    $fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $rows = [];
    foreach ($fixtures as $fx) {
        $rows[$fx['id']] = [
            $fx['id'],
            (int) $fx['supplier_pennies'],
            (int) $fx['margin_basis_points'],
            (int) $fx['vat_basis_points'],
            (int) $fx['expected_final_pennies'],
        ];
    }

    return $cache = $rows;
}

it('matches golden fixture to the penny (Phase 3 ship gate)', function (
    string $id,
    int $supplierPennies,
    int $marginBasisPoints,
    int $vatBasisPoints,
    int $expected,
): void {
    $calculator = new PriceCalculator;

    $actual = $calculator->compute($supplierPennies, $marginBasisPoints, $vatBasisPoints);

    expect($actual)->toBe(
        $expected,
        sprintf('%s: expected %dp, got %dp', $id, $expected, $actual),
    );
})->with(goldenFixtures());

it('loads exactly 80 golden fixture triples (50 v1 retail + 30 v2 trade)', function (): void {
    // Phase 9 Plan 03 — TRDE-03 extended the fixture from 50 → 80 entries.
    // The original 50 v1 retail triples (fx-001..fx-050) remain byte-identical
    // (locked by tests/Architecture/GoldenFixtureV1UnchangedTest.php sha256
    // blob hash). The 30 new v2 trade triples (fx-051..fx-080) carry the same
    // v1 keys (this test still passes for them) PLUS additional v2-only keys
    // (customer_group_id, lookup_customer_group_id, expected_resolution_source,
    // brand_id, category_id, rule_scope) — verified by GoldenFixtureV2TradeTest.
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($fixtures)->toHaveCount(80);

    foreach ($fixtures as $fx) {
        expect($fx)->toHaveKeys([
            'id', 'tier', 'supplier_pennies', 'margin_basis_points',
            'vat_basis_points', 'expected_final_pennies', 'source',
        ]);
    }
});
