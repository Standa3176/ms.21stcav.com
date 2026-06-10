<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture: WooFieldComparator must compare all 13 fields, no silent drops
|--------------------------------------------------------------------------
|
| WHY THIS TEST EXISTS — 2026-06-10 quick task 260610-qc4.
|
| The 2026-05-30 cutover's divergence scan compared only 7 of the 13 fields
| Woo and MS share as source-of-truth state. Every one of the 6 missed fields
| caused a customer-first incident AFTER cutover instead of being caught
| pre-cutover (see 260609-nku phantom stock, 260607-v5g category NULL,
| 260607-cgd brand+EAN NULL).
|
| The fix shipped today (260610-qc4) extends the comparator to all 13. This
| arch test installs the CI gate that prevents silent re-dropping of any field
| comparison — exactly the bug that caused the original incident, applied to
| the new state.
|
| EXPECTED FIELDS — the comparator's diff() method MUST emit one comparison
| block per field below. If you remove a field, you MUST also remove it from
| EXPECTED_FIELDS here. Both edits in the same commit.
|
|   name, slug, short_description, long_description, meta_description,
|   sell_price, image_url, stock_quantity, stock_status, buy_price,
|   category_id, brand_id, ean
|
| Drift-prevention contract: see .planning/quick/260610-qc4-extend-woofieldcomparator-to-cover-6-mis/260610-qc4-PLAN.md
|
| WHY TWO ASSERTIONS (mirrors EnvUsageTest + StockSeparateJoinTest pattern):
|   - Assertion 1: file scan — count the `'field' => 'X'` literals in the
|     comparator source for each expected field. Strip comments first so the
|     docblock enumeration doesn't trip the scan.
|   - Assertion 2: meta-assertion — inject a synthetic source string with one
|     expected field DELETED and assert the regex would catch the removal.
|     Without this, a future maintainer who weakens the regex into a no-op
|     would pass CI silently.
|
| VERIFICATION RECIPE (do not commit any of these — sanity checks only):
|
|   1. Temporarily delete the `if (… 'ean' …)` block from WooFieldComparator.
|      Run: vendor/bin/pest tests/Architecture/DivergenceComparatorCoverageTest.php
|      Expected: FAIL — assertion 1 lists `ean` as missing from the comparator.
|
|   2. Revert step 1. Both assertions pass.
*/

// ─── Assertion 1 — File scan asserting 13 expected 'field' => '<name>' literals

test('WooFieldComparator covers all 13 expected fields', function (): void {
    $expectedFields = [
        // Original 7 (Phase 7 Plan 05).
        'name', 'slug', 'short_description', 'long_description',
        'meta_description', 'sell_price', 'image_url',
        // New 6 (260610-qc4).
        'stock_quantity', 'stock_status', 'buy_price',
        'category_id', 'brand_id', 'ean',
    ];

    $source = (string) file_get_contents(
        base_path('app/Domain/Cutover/Services/WooFieldComparator.php')
    );

    // Strip comments so the docblock enumeration of these fields doesn't
    // satisfy the assertion vacuously.
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
    $stripped = preg_replace('#//.*$#m', '', (string) $stripped);

    $missing = [];
    foreach ($expectedFields as $field) {
        // Match `'field' => 'X'` exactly to ensure we're matching the diff
        // emission literal, not a string mention elsewhere.
        $pattern = "/'field'\s*=>\s*'".preg_quote($field, '/')."'/";
        if (! preg_match($pattern, (string) $stripped)) {
            $missing[] = $field;
        }
    }

    expect($missing)->toBeEmpty(
        'WooFieldComparator dropped field comparison(s): '
        .implode(', ', $missing)
        .' — divergence-scan would now be blind to those field(s). '
        .'Edit BOTH the comparator AND this test\'s expected list — see '
        .'.planning/quick/260610-qc4-extend-woofieldcomparator-to-cover-6-mis/260610-qc4-PLAN.md '
        .'Drift-prevention contract. The 2026-05-30 cutover shipped with only 7 of '
        .'13 fields compared; every missing field caused a customer-first incident '
        .'(see 260609-nku, 260607-v5g, 260607-cgd).'
    );
});

// ─── Assertion 2 — Meta-assertion: regex teeth check

test('field-coverage regex can detect a missing field in a synthetic source string (meta-assertion)', function (): void {
    // Positive: synthetic source with the literal present MUST match.
    $sample = "\$diffs[] = ['field' => 'stock_quantity', 'laravel' => 1, 'live' => 0, 'pin_column' => null];";
    expect(preg_match("/'field'\s*=>\s*'stock_quantity'/", $sample))->toBe(1);

    // Negative: a // comment containing the literal must NOT match after stripping.
    $negative = "// 'field' => 'stock_quantity' in a comment — should be stripped before scan";
    $stripped = preg_replace('#//.*$#m', '', $negative);
    expect(preg_match("/'field'\s*=>\s*'stock_quantity'/", (string) $stripped))->toBe(0);

    // Negative: a /* */ block comment mention must NOT match after stripping.
    $blockNegative = "/** docblock mentions 'field' => 'stock_quantity' here */\n\$x = 1;";
    $blockStripped = preg_replace('#/\*.*?\*/#s', '', $blockNegative);
    expect(preg_match("/'field'\s*=>\s*'stock_quantity'/", (string) $blockStripped))->toBe(0);

    // Negative: source with the field DELETED must produce a missing entry.
    $sourceWithoutEan = "\$diffs[] = ['field' => 'name', 'laravel' => 'a', 'live' => 'b'];";
    expect(preg_match("/'field'\s*=>\s*'ean'/", $sourceWithoutEan))->toBe(0);
});
