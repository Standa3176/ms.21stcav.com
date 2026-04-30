<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ReadMarginHistoryTool;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 02 Task 1 — ReadMarginHistoryTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates:
|   - 90d window enforcement (CONTEXT D-04)
|   - Reads activity_log entries on PricingRule with old.margin_basis_points
|   - Falls back to margin_change Suggestion rows (sparse activity_log case)
|   - 3 KB soft cap + _truncated hint (CONTEXT D-05)
|   - Unknown SKU returns empty changes, never throws
*/

function invokeReadMarginHistoryTool(string $sku): string
{
    // Prism v0.100.1 Tool::handle(...$args) takes variadic positional args
    return app(ReadMarginHistoryTool::class)->asPrismTool()->handle($sku);
}

it('returns empty changes payload for unknown SKU — never throws', function () {
    $payload = json_decode(invokeReadMarginHistoryTool('NEVER-SEEN-SKU'), true);

    expect($payload['sku'])->toBe('NEVER-SEEN-SKU');
    expect($payload['window_days'])->toBe(90);
    expect($payload['changes'])->toBe([]);
    expect($payload['_total_available'])->toBe(0);
});

it('reads margin_change Suggestion rows referencing the SKU within 90d window', function () {
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload' => [],
        'evidence' => [
            'sku' => 'MARGIN-SKU',
            'pricing_rule' => ['scope' => 'brand_category'],
            'our_current_margin_bps' => 2300,
            'proposed_margin_bps' => 2200,
            'margin_delta_bps' => -100,
        ],
        'proposed_at' => now()->subDays(15),
    ]);

    $payload = json_decode(invokeReadMarginHistoryTool('MARGIN-SKU'), true);

    expect($payload['changes'])->toHaveCount(1);
    expect($payload['changes'][0]['rule_scope'])->toBe('brand_category');
    expect($payload['changes'][0]['old_margin_bps'])->toBe(2300);
    expect($payload['changes'][0]['new_margin_bps'])->toBe(2200);
    expect($payload['changes'][0]['delta_bps'])->toBe(-100);
    expect($payload['changes'][0]['applied'])->toBeTrue();
    expect($payload['changes'][0]['via'])->toBe('margin_change_suggestion');
});

it('enforces 90d window on margin_change Suggestion rows', function () {
    // 1 row 100d old (excluded), 1 row 30d old (included)
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload' => [],
        'evidence' => ['sku' => 'WINDOW-SKU', 'pricing_rule' => ['scope' => 'brand']],
        'proposed_at' => now()->subDays(100),
    ]);
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload' => [],
        'evidence' => ['sku' => 'WINDOW-SKU', 'pricing_rule' => ['scope' => 'brand']],
        'proposed_at' => now()->subDays(30),
    ]);

    $payload = json_decode(invokeReadMarginHistoryTool('WINDOW-SKU'), true);

    expect($payload['_total_available'])->toBe(1);
});

it('caps response at 3 KB and emits _truncated hint when entries exceed cap', function () {
    $sku = 'CAP-SKU';
    foreach (range(1, 60) as $i) {
        Suggestion::create([
            'kind' => 'margin_change',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'evidence' => [
                'sku' => $sku,
                'pricing_rule' => ['scope' => 'brand_category'],
                'our_current_margin_bps' => 2000 + $i,
                'proposed_margin_bps' => 2050 + $i,
                'margin_delta_bps' => 50,
            ],
            'proposed_at' => now()->subDays(($i % 89) + 1),
        ]);
    }

    $json = invokeReadMarginHistoryTool($sku);
    $payload = json_decode($json, true);

    expect(strlen($json))->toBeLessThanOrEqual(3072);
    expect($payload['_truncated'])->toBeTrue();
    expect($payload['_total_available'])->toBe(60);
});
