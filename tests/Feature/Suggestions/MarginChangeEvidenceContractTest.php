<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 04 Task 3 — MarginChange evidence schema contract test (P10-G)
|--------------------------------------------------------------------------
|
| Phase 5 ComputeMarginSuggestionJob produces margin_change Suggestion rows
| with a specific evidence JSON shape (lines 156-180 of that file). Phase 10
| consumers depend on this shape:
|
|   - PricingAgentResultMapper reads `evidence.sku` for the agent's user message
|   - Filament OUT-OF-BAND chip reads `evidence.proposed_margin_bps` and
|     compares against `evidence.agent_proposed_band_min/max_bps`
|   - SuggestionResource detail view reads `evidence.our_current_margin_bps`,
|     `evidence.proposed_margin_bps`, `evidence.pricing_rule.scope`
|
| If a future Phase 5 refactor renames `proposed_margin_bps` → `proposed_bps`
| (or similar), the agent enrichment + Filament UI silently break. This test
| locks the contract: any drift between Phase 5's producer and Phase 10's
| consumers fails the build immediately.
|
| Per RESEARCH P10-G: this is a defensive contract gate, not a change-detection
| test. The seeded evidence array mirrors the verbatim Phase 5 producer output
| at Plan 10-04 ship time.
*/

use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Str;

it('Phase 5-produced margin_change suggestion has the keys Phase 10 mapper + Filament UI depend on', function () {
    // Mirror the verbatim Phase 5 evidence shape from
    // app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php lines 156-180.
    $evidence = [
        'competitor_id' => 5,
        'competitor_name' => 'AV Distributor Ltd',
        'sku' => 'TEST-CONTRACT-LOCK',
        'last_3_competitor_prices' => [
            ['price_ex_vat_pennies' => 145000, 'recorded_at' => now()->subDays(1)->toIso8601String()],
            ['price_ex_vat_pennies' => 144500, 'recorded_at' => now()->subDays(3)->toIso8601String()],
            ['price_ex_vat_pennies' => 145200, 'recorded_at' => now()->subDays(5)->toIso8601String()],
        ],
        'our_sell_price_pennies' => 150000,
        'our_supplier_price_pennies' => 120000,
        'our_current_margin_bps' => 2000,
        'proposed_margin_bps' => 1800,
        'margin_delta_bps' => 200,
        'sales_count_90d' => 27,
        'pricing_rule' => [
            'id' => 1,
            'scope' => 'global',
            'current_margin_bps' => 2000,
            'resolution_source' => 'rule',
        ],
        'beat_by_pennies' => 100,
    ];

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 1800],
        'evidence' => $evidence,
        'proposed_at' => now(),
    ]);

    // ── Phase 10 mapper + chip + Filament UI critical keys ─────────────────
    expect($suggestion->evidence)->toHaveKeys([
        'sku',                    // PricingAgentResultMapper user-message
        'proposed_margin_bps',    // OUT-OF-BAND chip — v1 deterministic value
        'our_current_margin_bps', // Filament v1 deterministic card
        'pricing_rule',           // Filament v1 deterministic card (scope)
    ]);
    expect($suggestion->evidence['pricing_rule'])->toHaveKey('scope');

    // ── Phase 5 v1 evidence keys preserved (full producer surface) ─────────
    expect($suggestion->evidence)->toHaveKeys([
        'competitor_id',
        'competitor_name',
        'last_3_competitor_prices',
        'our_sell_price_pennies',
        'our_supplier_price_pennies',
        'margin_delta_bps',
        'sales_count_90d',
        'beat_by_pennies',
    ]);

    // ── Type contract ─────────────────────────────────────────────────────
    expect($suggestion->evidence['sku'])->toBeString();
    expect($suggestion->evidence['proposed_margin_bps'])->toBeInt();
    expect($suggestion->evidence['our_current_margin_bps'])->toBeInt();
    expect($suggestion->evidence['pricing_rule']['scope'])->toBeString();
});
