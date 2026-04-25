<?php

declare(strict_types=1);

/**
 * Phase 9 Plan 03 Task 1 — TRDE-03 blob-hash regression guard.
 *
 * Captures the sha256 of the JSON-encoded first 50 entries (fx-001..fx-050)
 * of tests/Fixtures/Pricing/golden-fixtures.json. Phase 3 shipped under a
 * 50-triple penny-exact ship gate — Phase 9 must not regress those entries.
 *
 * If anyone edits a v1 triple, the hash drifts and this test fails the build.
 * v2 trade entries (fx-051..fx-080) are appended AFTER index 50 and are
 * NOT covered by this hash — see GoldenFixtureV2TradeTest for those.
 *
 * B-03 invariant: the hash literal below was captured AFTER asserting
 * `git diff --quiet tests/Fixtures/Pricing/golden-fixtures.json` exited 0.
 * If the test author skipped that precondition the snapshot may already
 * include drift — re-running STEP 0 + STEP 1 against a clean tree is the
 * remediation. The capture also asserted the v1 services were clean
 * (`git diff --quiet app/Domain/Pricing/Services/{RuleResolver,PriceCalculator}.php`).
 *
 * Real fixture shape (verified at capture time):
 *   id, tier, supplier_pennies, margin_basis_points, vat_basis_points,
 *   expected_final_pennies, source.
 *
 * Phase 9 v2 entries (fx-051..fx-080) ADD: customer_group_id,
 * lookup_customer_group_id, expected_resolution_source, brand_id, category_id,
 * rule_scope (and optional override fields). v1 entries do NOT carry any of
 * those keys — their absence is asserted below as a defensive guard against
 * mid-array inserts.
 */

it('v1 50-triple golden fixture is byte-identical to pre-Phase-9 snapshot', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $raw = file_get_contents($path);
    $fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $v1 = array_slice($fixtures, 0, 50);

    $v1Json = json_encode($v1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', $v1Json);

    // Captured AFTER B-03 git-clean precondition (`git diff --quiet golden-fixtures.json`
    // AND `git diff --quiet app/Domain/Pricing/Services/{RuleResolver,PriceCalculator}.php`
    // both exited 0) and BEFORE Plan 09-03 Task 2 edits land.
    // CONTEXT.md D-05 + RESEARCH §Assumptions Log A2.
    $expected = 'f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967';

    expect($hash)->toBe(
        $expected,
        'v1 50-triple golden fixture has drifted — Phase 3 ship gate broken. '
        .'Original triples MUST remain byte-identical (CONTEXT.md D-05). '
        .'If you intentionally edited a v1 triple, you have shipped the wrong change.'
    );
});

it('total fixture count is 80 (50 v1 retail + 30 v2 trade)', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($fixtures)->toHaveCount(80);
});

it('v1 entries fx-001..fx-050 do NOT contain customer_group_id field', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $v1 = array_slice($fixtures, 0, 50);

    foreach ($v1 as $entry) {
        expect(array_key_exists('customer_group_id', $entry))->toBeFalse(
            "v1 entry {$entry['id']} unexpectedly contains customer_group_id — v1 portion must remain pristine"
        );
    }
});
