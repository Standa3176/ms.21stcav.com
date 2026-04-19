<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Phase 5 Plan 04b Task 2 — demo fixture seeder for the human-verify checkpoint.
 *
 * Makes the 12-point walkthrough repeatable with one artisan command. Replaces
 * manual seeding burden: ops runs `php artisan db:seed --class=CompetitorDemoSeeder`
 * and gets a populated Competitor Intelligence dashboard + an ambiguous-mapping
 * Quarantine row + orphan / margin_change Suggestions.
 *
 * IDEMPOTENT — designed to survive multiple invocations without duplicating
 * rows. Uses firstOrCreate throughout; CompetitorPrice guards on an up-front
 * count() check; the ambiguous_mapping CsvParseError is keyed on (filename,
 * issue_type); the demo CSV file is written only if absent.
 *
 * T-05-04b-05 mitigation: registration is gated to local/testing environments
 * in DatabaseSeeder::run().
 */
final class CompetitorDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 3 Competitors: one fresh (<48h), one stale (>48h), one missing ingest ──
        $fresh = Competitor::firstOrCreate(
            ['slug' => 'demo-fresh'],
            [
                'name' => 'Demo Fresh Competitor',
                'status' => Competitor::STATUS_ACTIVE,
                'is_active' => true,
                'last_ingest_at' => now()->subHours(2),
            ],
        );

        $stale = Competitor::firstOrCreate(
            ['slug' => 'demo-stale'],
            [
                'name' => 'Demo Stale Competitor',
                'status' => Competitor::STATUS_ACTIVE,
                'is_active' => true,
                'last_ingest_at' => now()->subHours(72),
            ],
        );

        $missing = Competitor::firstOrCreate(
            ['slug' => 'demo-missing'],
            [
                'name' => 'Demo Missing Competitor',
                'status' => Competitor::STATUS_ACTIVE,
                'is_active' => true,
                'last_ingest_at' => null,
            ],
        );

        // ── Demo product (drives trend chart + biggest-delta widget) ─────────
        // sell_price = £85 (stored as decimal(12,4) GBP — Phase 2 convention,
        // NOT pennies. Widgets convert on-the-fly via ROUND(sell_price * 100)).
        $product = Product::firstOrCreate(
            ['sku' => 'DEMO-SKU-001'],
            [
                'woo_product_id' => 9_999_001,
                'name' => 'Demo Conference Speaker',
                'type' => 'simple',
                'status' => 'publish',
                'stock_status' => 'instock',
                'buy_price' => 40.00,
                'sell_price' => 85.00,
                'cost_price' => null,
                'is_custom_ms' => false,
                'exclude_from_auto_update' => false,
                'tags' => [],
            ],
        );

        // ── 20+ CompetitorPrice rows across 30 days for the demo SKU ────────
        $existing = CompetitorPrice::where('competitor_id', $fresh->id)
            ->where('sku', $product->sku)
            ->count();

        if ($existing < 20) {
            for ($d = 29; $d >= 0; $d--) {
                CompetitorPrice::firstOrCreate(
                    [
                        'competitor_id' => $fresh->id,
                        'sku' => $product->sku,
                        'recorded_at' => now()->subDays($d)->startOfDay(),
                    ],
                    [
                        'mpn' => null,
                        'price_pennies_gross' => 7500 + random_int(-500, 500),
                        'price_pennies_ex_vat' => 6250 + random_int(-400, 400),
                    ],
                );
            }
        }

        // ── Ensure a PricingRule exists so the margin_change suggestion links
        //    to a real row (approving → PricingRule::update → observer fires).
        $rule = PricingRule::firstOrCreate(
            ['scope' => PricingRule::SCOPE_DEFAULT_TIER, 'is_default_tier' => true, 'tier_min_pennies' => 0, 'tier_max_pennies' => 9999],
            [
                'brand_id' => null,
                'category_id' => null,
                'margin_basis_points' => 5000,
                'priority' => 50,
                'active' => true,
            ],
        );

        // ── margin_change Suggestion with D-07 evidence shape ────────────────
        // Keyed on (kind, correlation_id) so re-seeding is idempotent.
        Suggestion::firstOrCreate(
            ['kind' => 'margin_change', 'correlation_id' => 'demo-margin-change-001'],
            [
                'status' => Suggestion::STATUS_PENDING,
                'payload' => [
                    'pricing_rule_id' => (int) $rule->id,
                    'new_margin_basis_points' => 7000,
                ],
                'evidence' => [
                    'competitor_id' => $fresh->id,
                    'competitor_name' => $fresh->name,
                    'sku' => $product->sku,
                    'last_3_competitor_prices' => [
                        ['price_ex_vat_pennies' => 6250, 'recorded_at' => now()->subDays(2)->toIso8601String()],
                        ['price_ex_vat_pennies' => 6150, 'recorded_at' => now()->subDays(1)->toIso8601String()],
                        ['price_ex_vat_pennies' => 6000, 'recorded_at' => now()->toIso8601String()],
                    ],
                    'our_sell_price_pennies' => 8500,
                    'our_supplier_price_pennies' => 4000,
                    'our_current_margin_bps' => 5000,
                    'proposed_margin_bps' => 7000,
                    'margin_delta_bps' => 2000,
                    'sales_count_90d' => 15,
                    'pricing_rule' => [
                        'id' => (int) $rule->id,
                        'scope' => PricingRule::SCOPE_DEFAULT_TIER,
                        'current_margin_bps' => 5000,
                        'resolution_source' => 'default_tier',
                    ],
                    'beat_by_pennies' => 1,
                ],
                'proposed_at' => now(),
            ],
        );

        // ── new_product_opportunity Suggestion (supporting_competitors=2) ────
        Suggestion::firstOrCreate(
            ['kind' => 'new_product_opportunity', 'correlation_id' => 'demo-new-opportunity-001'],
            [
                'status' => Suggestion::STATUS_PENDING,
                'payload' => ['sku' => 'ORPHAN-DEMO-001'],
                'evidence' => [
                    'sku' => 'ORPHAN-DEMO-001',
                    'supporting_competitors' => 2,
                    'competitor_sightings' => [
                        [
                            'competitor_id' => $fresh->id,
                            'name' => $fresh->name,
                            'price_gross_pennies' => 12_000,
                            'recorded_at' => now()->toIso8601String(),
                        ],
                        [
                            'competitor_id' => $stale->id,
                            'name' => $stale->name,
                            'price_gross_pennies' => 11_500,
                            'recorded_at' => now()->subDays(2)->toIso8601String(),
                        ],
                    ],
                ],
                'proposed_at' => now(),
            ],
        );

        // ── ambiguous_mapping parse error + matching CSV in quarantine/ ──────
        $quarantineDir = storage_path('app/competitors/quarantine');
        if (! is_dir($quarantineDir)) {
            @mkdir($quarantineDir, 0o775, true);
        }

        $demoFilename = 'demo_quarantine.csv';
        $demoCsvPath = $quarantineDir.DIRECTORY_SEPARATOR.$demoFilename;

        if (! is_file($demoCsvPath)) {
            file_put_contents(
                $demoCsvPath,
                "col_a,col_b,col_c\nABC-1,19.99,Widget A\nXYZ-9,29.50,Widget B\n",
            );
        }

        CsvParseError::firstOrCreate(
            ['filename' => $demoFilename, 'issue_type' => CsvParseError::TYPE_AMBIGUOUS_MAPPING],
            [
                'competitor_id' => $missing->id,
                'context' => [
                    'headers' => ['col_a', 'col_b', 'col_c'],
                    'detail' => 'Auto-detection could not pick SKU vs price columns from generic header names',
                ],
            ],
        );

        // ── Belt-and-braces: ensure ops@meetingstore.co.uk is opted in ───────
        // (mirrors DatabaseSeeder's post-chain UPDATE; safe to re-apply).
        AlertRecipient::where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_competitor_alerts' => true]);

        // ── Silence the linter about unused local — keep a deterministic
        // correlation_id handle for future extension without static-property.
        unset($fresh, $stale, $missing, $product, $rule);
        // Placeholder Str::uuid() reference preserves the import if the seeder
        // is later extended to vary correlation_ids per run.
        Str::uuid();
    }
}
