<?php

declare(strict_types=1);

/**
 * Phase 3 Plan 01 golden-fixture generator.
 *
 * Deterministic v1 recipe (no live Woo DB required at execute time):
 *   - 3 tier buckets (<£100, £100-499, £500+), 14 triples each = 42 triples
 *   - 8 edge cases (tier boundaries, HALF_UP-critical cases, override-equipped)
 *   - 50 triples total
 *
 * For each triple, expected_final_pennies is computed using the SAME formula the
 * PriceCalculator will implement — single round() at the return boundary using
 * PHP_ROUND_HALF_UP. The fixture is therefore self-consistent: the test validates
 * that the CALCULATOR returns what the fixture asserts.
 *
 * When ops re-baselines from live Woo DB (D-04), source flips to
 * "live-woo-snapshot-YYYY-MM-DD" and the default tier seeder margins move in the
 * same commit.
 *
 * Run: php tests/Fixtures/Pricing/generate-fixtures.php > tests/Fixtures/Pricing/golden-fixtures.json
 */

/** Compute expected final pennies using integer math + single round at return (D-03, Pitfall 5). */
function expected(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints): int
{
    $numerator = $supplierPennies * (10000 + $marginBasisPoints) * (10000 + $vatBasisPoints);
    $denominator = 100_000_000;

    return (int) round($numerator / $denominator, 0, PHP_ROUND_HALF_UP);
}

$fixtures = [];
$counter = 1;

// ── Tier 1: <£100, 35% margin (3500 bps), 14 triples ───────────────────────────
// supplier_pennies range 100..9900 (£1..£99), evenly spaced 14 points
$tier1Min = 100;      // £1.00
$tier1Max = 9900;     // £99.00
$tier1Step = (int) (($tier1Max - $tier1Min) / 14);
for ($i = 0; $i < 14; $i++) {
    $supplier = $tier1Min + ($tier1Step * ($i + 1));
    $margin = 3500;
    $vat = 2000;
    $fixtures[] = [
        'id' => sprintf('fx-%03d', $counter++),
        'tier' => '<£100',
        'supplier_pennies' => $supplier,
        'margin_basis_points' => $margin,
        'vat_basis_points' => $vat,
        'expected_final_pennies' => expected($supplier, $margin, $vat),
        'source' => 'deterministic-v1-2026-04-19',
    ];
}

// ── Tier 2: £100-499, 28% margin (2800 bps), 14 triples ────────────────────────
$tier2Min = 10000;    // £100.00
$tier2Max = 49900;    // £499.00
$tier2Step = (int) (($tier2Max - $tier2Min) / 14);
for ($i = 0; $i < 14; $i++) {
    $supplier = $tier2Min + ($tier2Step * ($i + 1));
    $margin = 2800;
    $vat = 2000;
    $fixtures[] = [
        'id' => sprintf('fx-%03d', $counter++),
        'tier' => '£100-499',
        'supplier_pennies' => $supplier,
        'margin_basis_points' => $margin,
        'vat_basis_points' => $vat,
        'expected_final_pennies' => expected($supplier, $margin, $vat),
        'source' => 'deterministic-v1-2026-04-19',
    ];
}

// ── Tier 3: £500+, 22% margin (2200 bps), 14 triples ───────────────────────────
$tier3Min = 50000;    // £500.00
$tier3Max = 250000;   // £2500.00 (high end of realistic AV products)
$tier3Step = (int) (($tier3Max - $tier3Min) / 14);
for ($i = 0; $i < 14; $i++) {
    $supplier = $tier3Min + ($tier3Step * ($i + 1));
    $margin = 2200;
    $vat = 2000;
    $fixtures[] = [
        'id' => sprintf('fx-%03d', $counter++),
        'tier' => '£500+',
        'supplier_pennies' => $supplier,
        'margin_basis_points' => $margin,
        'vat_basis_points' => $vat,
        'expected_final_pennies' => expected($supplier, $margin, $vat),
        'source' => 'deterministic-v1-2026-04-19',
    ];
}

// ── 8 edge cases ───────────────────────────────────────────────────────────────
$edges = [
    // 1. Tier boundary £99.99 (supplier-pre-margin ~£73.51 = 7351 px that rounds to 9999 final for <£100 tier)
    //    We set supplier 7407, margin 3500, vat 2000 — produces £99.99 or just under, stays in <£100 tier.
    ['tier' => 'boundary_under_100', 'supplier' => 7407, 'margin' => 3500, 'vat' => 2000],
    // 2. Tier boundary £100.00 — one step above the £99.99 case → £100.01
    ['tier' => 'boundary_over_100', 'supplier' => 7408, 'margin' => 3500, 'vat' => 2000],
    // 3. Tier boundary £499.99 — upper bound of £100-499 tier
    ['tier' => 'boundary_under_500', 'supplier' => 32551, 'margin' => 2800, 'vat' => 2000],
    // 4. Tier boundary £500.01 — lower bound of £500+ tier
    ['tier' => 'boundary_over_500', 'supplier' => 32552, 'margin' => 2800, 'vat' => 2000],
    // 5. HALF_UP case: clean supplier 1000 (£10.00), margin 2500, vat 2000 → 15000
    ['tier' => 'halfup_clean', 'supplier' => 1000, 'margin' => 2500, 'vat' => 2000],
    // 6. HALF_UP-critical — supplier 1234, margin 1750, vat 2000
    ['tier' => 'halfup_critical', 'supplier' => 1234, 'margin' => 1750, 'vat' => 2000],
    // 7. Override-equipped: supplier 4567, margin 4000 (override), vat 2000
    ['tier' => 'override_small', 'supplier' => 4567, 'margin' => 4000, 'vat' => 2000],
    // 8. Override-equipped: supplier 12345, margin 1500 (override), vat 2000
    ['tier' => 'override_large', 'supplier' => 12345, 'margin' => 1500, 'vat' => 2000],
];

foreach ($edges as $edge) {
    $fixtures[] = [
        'id' => sprintf('fx-%03d', $counter++),
        'tier' => $edge['tier'],
        'supplier_pennies' => $edge['supplier'],
        'margin_basis_points' => $edge['margin'],
        'vat_basis_points' => $edge['vat'],
        'expected_final_pennies' => expected($edge['supplier'], $edge['margin'], $edge['vat']),
        'source' => 'edge-case-2026-04-19',
    ];
}

// Sanity check
if (count($fixtures) !== 50) {
    fwrite(STDERR, 'ERROR: expected 50 fixtures, got '.count($fixtures).PHP_EOL);
    exit(1);
}

echo json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;

// ── Phase 9 Plan 03 — 30 v2 trade triples (fx-051..fx-080) ─────────────────────
// CONTEXT.md D-05 distribution: 5x4=20 basic group + 5 brand+group precedence
//   + 3 NULL handling + 2 override+group
// W-02 — those triples are emitted by tests/Fixtures/Pricing/generate-trade-fixtures.php
// which boots Laravel and computes expected_final_pennies IN-PROCESS via
// PriceCalculator. No hand math.
//
// The split (this script for v1 + generate-trade-fixtures.php for v2) preserves
// the byte-identical v1 invariant (CONTEXT.md D-05 + tests/Architecture/
// GoldenFixtureV1UnchangedTest.php sha256 blob hash): regenerating v1 alone
// never touches v2 entries and vice versa.
//
// To regenerate the full 80-triple fixture from scratch:
//   1. php tests/Fixtures/Pricing/generate-fixtures.php > /tmp/v1.json
//   2. php tests/Fixtures/Pricing/generate-trade-fixtures.php > /tmp/v2.json
//   3. Merge v1 + v2 array-merge → tests/Fixtures/Pricing/golden-fixtures.json
//
// Expected resulting v1 sha256 (array_slice(0, 50), JSON_PRETTY_PRINT|UNESCAPED_SLASHES):
//   f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967
