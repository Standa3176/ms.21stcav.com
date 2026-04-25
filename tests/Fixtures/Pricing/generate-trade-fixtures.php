<?php

declare(strict_types=1);

/**
 * Phase 9 Plan 03 Task 2 — W-02 in-process trade fixture generator.
 *
 * Boots the Laravel app, builds 30 trade triples per CONTEXT.md D-05
 * distribution (5x4 basic + 5 brand+group precedence + 3 NULL + 2 override),
 * computes each `expected_final_pennies` by calling PriceCalculator
 * IN-PROCESS, and emits the 30-entry JSON fragment for append to
 * tests/Fixtures/Pricing/golden-fixtures.json.
 *
 * Run via:
 *   php tests/Fixtures/Pricing/generate-trade-fixtures.php > /tmp/trade-triples.json
 *
 * **W-02 — eliminates hand-computation.** Each value emitted by this script
 * is what the production PriceCalculator actually produces on the same
 * (supplier_pennies, margin_basis_points, vat_basis_points) input. The
 * triples' `expected_resolution_source` strings are stable enums on
 * TradeRuleResolver (verified by Plan 09-02 Task 2 unit tests) so we do not
 * need to hit the DB resolver to be confident which source a triple resolves
 * to — the fixture's source field is the contract Plan 09-03 Task 3 then
 * asserts against actual TradeRuleResolver output.
 *
 * Fixture row shape (v1 keys + v2 trade fields):
 *   id, tier, supplier_pennies, margin_basis_points, vat_basis_points,
 *   expected_final_pennies, source,                        ← v1 keys (kept so
 *                                                            existing Phase 3
 *                                                            ship-gate test
 *                                                            stays green)
 *   customer_group_id, lookup_customer_group_id,
 *   expected_resolution_source, brand_id, category_id,
 *   rule_scope, has_product_override, override_margin_basis_points
 *                                                          ← v2 trade fields
 *
 * MySQL-offline note: PriceCalculator is pure (no DB, no events). The
 * compute() call uses `config('pricing.rounding_mode')` which resolves via
 * Laravel's config repository — booting the kernel handles that. When
 * meetingstore_ops_testing MySQL is online, this script can ALSO assert
 * against TradeRuleResolver source strings by building products + rules
 * (commented out by default; see ASSERT_RESOLVER constant).
 *
 * Customer-group ID convention: after migrate:fresh + db:seed, the
 * Phase9\CustomerGroupSeeder firstOrCreate pattern produces:
 *   trade => 1, reseller => 2, education => 3, nhs => 4
 * This script uses those PKs directly. If a non-fresh DB has different IDs
 * the customer_group_id column on emitted triples will need manual review —
 * but the PriceCalculator math is invariant to which group_id is assigned.
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Pricing\Services\PriceCalculator;

$calculator = $app->make(PriceCalculator::class);

// Customer group PKs after Phase9\CustomerGroupSeeder firstOrCreate (slug → id).
$TRADE = 1;
$RESELLER = 2;
$EDUCATION = 3;
$NHS = 4;

/**
 * Each spec declares the supplier/margin/VAT triple PLUS the trade-pricing
 * metadata that downstream tests assert against (rule_scope, source, etc.).
 *
 * margin_basis_points represents the margin the resolver returns once it
 * picks the winning rule — it's the value PriceCalculator::compute() will
 * be called with. For 'override' rows the margin is the override's margin
 * (Layer 0 invariant — beats trade and retail).
 *
 * `expected_resolution_source` is the TradeRuleResolver source enum for
 * v2 callers (`trade_brand_category`, `trade_brand`, `trade_category`,
 * `trade_default_tier`) or the v1 source enum when the triple is a NULL
 * fall-through (`brand_category` etc.) or override (`override`).
 */
$specs = [
    // ── Group A: basic group calculation — 5 sub-scenarios x 4 groups = 20 ──
    // Each group repeats the same 5-shape pattern with deterministic supplier
    // prices spaced 1000 px apart per group; ids run fx-051..fx-070 contiguously.
    ...buildGroupA('trade',     $TRADE,     5100, 51),
    ...buildGroupA('reseller',  $RESELLER,  6100, 56),
    ...buildGroupA('education', $EDUCATION, 7100, 61),
    ...buildGroupA('nhs',       $NHS,       8100, 66),

    // ── Group B: brand+group precedence (5 triples, fx-071..fx-075) ─────────
    // Group rule WINS over a same-scope retail rule. Resolver returns the
    // group's margin (typically lower, hence different expected_final_pennies
    // from a hypothetical retail-only resolution — but the TEST only checks
    // what the resolver+calculator actually produce for the trade margin).
    ['id' => 'fx-071', 'tier' => 'precedence_trade',     'supplier' => 9100, 'margin' => 1800, 'vat' => 2000,
        'group_id' => $TRADE,    'lookup_group_id' => $TRADE,    'rule_scope' => 'brand_category',
        'brand_id' => 11, 'category_id' => 21, 'source' => 'trade_brand_category'],
    ['id' => 'fx-072', 'tier' => 'precedence_reseller',  'supplier' => 9200, 'margin' => 2000, 'vat' => 2000,
        'group_id' => $RESELLER, 'lookup_group_id' => $RESELLER, 'rule_scope' => 'brand_category',
        'brand_id' => 12, 'category_id' => 22, 'source' => 'trade_brand_category'],
    ['id' => 'fx-073', 'tier' => 'precedence_education', 'supplier' => 9300, 'margin' => 2200, 'vat' => 2000,
        'group_id' => $EDUCATION,'lookup_group_id' => $EDUCATION,'rule_scope' => 'brand_category',
        'brand_id' => 13, 'category_id' => 23, 'source' => 'trade_brand_category'],
    ['id' => 'fx-074', 'tier' => 'precedence_nhs',       'supplier' => 9400, 'margin' => 1500, 'vat' => 2000,
        'group_id' => $NHS,      'lookup_group_id' => $NHS,      'rule_scope' => 'brand_category',
        'brand_id' => 14, 'category_id' => 24, 'source' => 'trade_brand_category'],
    ['id' => 'fx-075', 'tier' => 'precedence_trade_brand_only', 'supplier' => 9500, 'margin' => 2400, 'vat' => 2000,
        'group_id' => $TRADE,    'lookup_group_id' => $TRADE,    'rule_scope' => 'brand',
        'brand_id' => 15, 'category_id' => null, 'source' => 'trade_brand'],

    // ── Group C: NULL handling per Pitfall B1 (3 triples, fx-076..fx-078) ──
    // Each entry has customer_group_id = null in the FIXTURE definition —
    // meaning "this rule is a retail rule" — and lookup_customer_group_id
    // varies (null, 0, non-existent). All three should resolve through the
    // base v1 RuleResolver (no trade_* source).
    ['id' => 'fx-076', 'tier' => 'null_lookup',           'supplier' => 10100, 'margin' => 2500, 'vat' => 2000,
        'group_id' => null, 'lookup_group_id' => null,  'rule_scope' => 'brand_category',
        'brand_id' => 31, 'category_id' => 41, 'source' => 'brand_category'],
    ['id' => 'fx-077', 'tier' => 'zero_lookup',           'supplier' => 10200, 'margin' => 2700, 'vat' => 2000,
        'group_id' => null, 'lookup_group_id' => 0,     'rule_scope' => 'brand_category',
        'brand_id' => 32, 'category_id' => 42, 'source' => 'brand_category'],
    ['id' => 'fx-078', 'tier' => 'nonexistent_lookup',    'supplier' => 10300, 'margin' => 2900, 'vat' => 2000,
        'group_id' => null, 'lookup_group_id' => 99999, 'rule_scope' => 'brand_category',
        'brand_id' => 33, 'category_id' => 43, 'source' => 'brand_category'],

    // ── Group D: override+group (2 triples, fx-079, fx-080) ─────────────────
    // ProductOverride beats every rule including trade rules with priority+100
    // (Pitfall 3 invariant). Resolver returns the override's margin and source
    // 'override'. lookup_group_id is set so the trade-aware path is exercised
    // BUT the override Layer 0 short-circuits before any group lookup runs.
    ['id' => 'fx-079', 'tier' => 'override_trade_small', 'supplier' => 4567, 'margin' => 4000, 'vat' => 2000,
        'group_id' => $TRADE, 'lookup_group_id' => $TRADE, 'rule_scope' => 'override',
        'brand_id' => 51, 'category_id' => 61, 'source' => 'override',
        'has_product_override' => true,  'override_margin_basis_points' => 4000],
    ['id' => 'fx-080', 'tier' => 'override_trade_large', 'supplier' => 12345, 'margin' => 1500, 'vat' => 2000,
        'group_id' => $TRADE, 'lookup_group_id' => $TRADE, 'rule_scope' => 'override',
        'brand_id' => 52, 'category_id' => 62, 'source' => 'override',
        'has_product_override' => true,  'override_margin_basis_points' => 1500],
];

$output = [];
foreach ($specs as $spec) {
    // W-02 — expected_final_pennies computed IN-PROCESS via PriceCalculator.
    $expected = $calculator->compute(
        $spec['supplier'],
        $spec['margin'],
        $spec['vat'],
    );

    $row = [
        'id' => $spec['id'],
        'tier' => $spec['tier'],
        'supplier_pennies' => $spec['supplier'],
        'margin_basis_points' => $spec['margin'],
        'vat_basis_points' => $spec['vat'],
        'expected_final_pennies' => $expected,
        'source' => 'trade-v2-2026-04-25',
        'customer_group_id' => $spec['group_id'],
        'lookup_customer_group_id' => $spec['lookup_group_id'],
        'rule_scope' => $spec['rule_scope'],
        'brand_id' => $spec['brand_id'],
        'category_id' => $spec['category_id'],
        'expected_resolution_source' => $spec['source'],
    ];

    if (! empty($spec['has_product_override'])) {
        $row['has_product_override'] = true;
        $row['override_margin_basis_points'] = $spec['override_margin_basis_points'];
    }

    $output[] = $row;
}

if (count($output) !== 30) {
    fwrite(STDERR, 'ERROR: expected 30 trade fixtures, got '.count($output).PHP_EOL);
    exit(1);
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

/**
 * Build 5 sub-scenarios for one customer group (Group A pattern).
 *
 * Sub-scenarios per group:
 *   1. trade_brand_category — brand+category set, group rule applies
 *   2. trade_brand          — brand only
 *   3. trade_category       — category only
 *   4. trade_default_tier   — no brand/category, tier rule applies
 *   5. fall-through         — group set but no group rule; falls through to
 *                              v1 retail rule (source = base 'brand_category')
 */
function buildGroupA(string $groupName, int $groupId, int $supplierBase, int $idStart): array
{
    return [
        ['id' => sprintf('fx-%03d', $idStart),
            'tier' => "{$groupName}_brand_category",
            'supplier' => $supplierBase, 'margin' => 1800, 'vat' => 2000,
            'group_id' => $groupId, 'lookup_group_id' => $groupId,
            'rule_scope' => 'brand_category', 'brand_id' => 1, 'category_id' => 1,
            'source' => 'trade_brand_category'],
        ['id' => sprintf('fx-%03d', $idStart + 1),
            'tier' => "{$groupName}_brand_only",
            'supplier' => $supplierBase + 100, 'margin' => 2000, 'vat' => 2000,
            'group_id' => $groupId, 'lookup_group_id' => $groupId,
            'rule_scope' => 'brand', 'brand_id' => 2, 'category_id' => null,
            'source' => 'trade_brand'],
        ['id' => sprintf('fx-%03d', $idStart + 2),
            'tier' => "{$groupName}_category_only",
            'supplier' => $supplierBase + 200, 'margin' => 2200, 'vat' => 2000,
            'group_id' => $groupId, 'lookup_group_id' => $groupId,
            'rule_scope' => 'category', 'brand_id' => null, 'category_id' => 3,
            'source' => 'trade_category'],
        ['id' => sprintf('fx-%03d', $idStart + 3),
            'tier' => "{$groupName}_default_tier",
            'supplier' => $supplierBase + 300, 'margin' => 2500, 'vat' => 2000,
            'group_id' => $groupId, 'lookup_group_id' => $groupId,
            'rule_scope' => 'default_tier', 'brand_id' => null, 'category_id' => null,
            'source' => 'trade_default_tier'],
        ['id' => sprintf('fx-%03d', $idStart + 4),
            'tier' => "{$groupName}_fallthrough_to_retail",
            'supplier' => $supplierBase + 400, 'margin' => 3500, 'vat' => 2000,
            'group_id' => $groupId, 'lookup_group_id' => $groupId,
            'rule_scope' => 'brand_category', 'brand_id' => 4, 'category_id' => 4,
            // No trade rule for this product+group; fall-through hits v1 retail.
            'source' => 'brand_category'],
    ];
}
