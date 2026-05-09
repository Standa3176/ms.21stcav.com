<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — suggestions:auto-apply
|--------------------------------------------------------------------------
|
| Replaces the legacy WP plugin's setPer() ≥ 8% auto-apply behaviour. The
| command loops Suggestions where kind=margin_change AND status=pending AND
| auto_apply_eligible=true, computes |new - old| basis-point delta, and
| dispatches ApplySuggestionJob for any row crossing
| pricing.auto_apply_threshold_bps.
*/

it('registers suggestions:auto-apply as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('suggestions:auto-apply');
});

it('dispatches ApplySuggestionJob for eligible pending margin_change Suggestions above threshold', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    $suggestion = makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 5800, eligible: true);

    Artisan::call('suggestions:auto-apply');

    Bus::assertDispatched(ApplySuggestionJob::class, function (ApplySuggestionJob $job) use ($suggestion): bool {
        return $job->suggestionId === (string) $suggestion->id;
    });
});

it('skips suggestions whose delta is below threshold', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 5500, eligible: true); // 500 < 800

    Artisan::call('suggestions:auto-apply');

    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

it('skips suggestions where auto_apply_eligible is false', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: false);

    Artisan::call('suggestions:auto-apply');

    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

it('skips suggestions that are not pending (already applied / rejected)', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    $s = makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: true);
    $s->update(['status' => Suggestion::STATUS_APPLIED]);

    Artisan::call('suggestions:auto-apply');

    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

it('skips kinds other than margin_change', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    Suggestion::forceCreate([
        'kind' => 'crm_push_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'kind-test',
        'payload' => ['old_margin_basis_points' => 5000, 'new_margin_basis_points' => 7000],
        'evidence' => [],
        'proposed_at' => now(),
        'auto_apply_eligible' => true,
    ]);

    Artisan::call('suggestions:auto-apply');

    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

it('--dry-run dispatches nothing but reports the count', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: true);

    $exit = Artisan::call('suggestions:auto-apply', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

it('--limit caps total dispatches', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: true);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: true);
    makeMarginSuggestion($rule->id, oldBps: 5000, newBps: 7000, eligible: true);

    Artisan::call('suggestions:auto-apply', ['--limit' => 2]);

    Bus::assertDispatchedTimes(ApplySuggestionJob::class, 2);
});

it('skips suggestions with missing payload fields rather than throwing', function (): void {
    Bus::fake();
    config()->set('pricing.auto_apply_threshold_bps', 800);

    Suggestion::forceCreate([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'missing-payload',
        'payload' => ['pricing_rule_id' => 1], // no old / new bps
        'evidence' => [],
        'proposed_at' => now(),
        'auto_apply_eligible' => true,
    ]);

    $exit = Artisan::call('suggestions:auto-apply');

    expect($exit)->toBe(0);
    Bus::assertNotDispatched(ApplySuggestionJob::class);
});

// ── helpers ──

function makeMarginSuggestion(int $ruleId, int $oldBps, int $newBps, bool $eligible): Suggestion
{
    return Suggestion::forceCreate([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-'.uniqid(),
        'payload' => [
            'pricing_rule_id' => $ruleId,
            'old_margin_basis_points' => $oldBps,
            'new_margin_basis_points' => $newBps,
        ],
        'evidence' => ['sku' => 'AUTO-APPLY-TEST'],
        'proposed_at' => now(),
        'auto_apply_eligible' => $eligible,
    ]);
}
