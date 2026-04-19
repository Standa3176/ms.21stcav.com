<?php

declare(strict_types=1);

use App\Domain\Competitor\Appliers\MarginChangeApplier;
use App\Domain\Pricing\Events\PricingRuleChanged;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 3 — MarginChangeApplier (D-12 second real producer)
|--------------------------------------------------------------------------
|
| Approving a margin_change Suggestion triggers ApplySuggestionJob →
| resolver → MarginChangeApplier::apply() → updates PricingRule via
| Eloquent → PricingRuleObserver fires PricingRuleChanged event. That's
| the full chain from approval → price recompute trigger point.
*/

it('supports() returns the margin_change kind', function (): void {
    expect((new MarginChangeApplier(app(\App\Foundation\Audit\Services\Auditor::class)))->supports())
        ->toBe(['margin_change']);
});

it('apply() updates PricingRule.margin_basis_points and returns a result array with before/after', function (): void {
    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'apply-test-1',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'POP-SKU', 'sales_count_90d' => 15],
        'proposed_at' => now(),
    ]);

    $applier = app(MarginChangeApplier::class);
    $result = $applier->apply($suggestion);

    expect($result)->toBeArray();
    expect($result['applied'] ?? null)->toBeTrue();
    expect($result['pricing_rule_id'] ?? null)->toBe($rule->id);
    expect($result['old_margin_bps'] ?? null)->toBe(5000);
    expect($result['new_margin_bps'] ?? null)->toBe(7000);

    expect($rule->fresh()->margin_basis_points)->toBe(7000);
});

it('apply() fires PricingRuleChanged through the observer chain (not direct dispatch)', function (): void {
    Event::fake([PricingRuleChanged::class]);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'apply-test-2',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'POP-SKU'],
        'proposed_at' => now(),
    ]);

    app(MarginChangeApplier::class)->apply($suggestion);

    Event::assertDispatched(PricingRuleChanged::class, function (PricingRuleChanged $event) use ($rule) {
        return $event->ruleId === $rule->id
            && $event->oldMarginBps === 5000
            && $event->newMarginBps === 7000;
    });
});

it('apply() throws when the referenced PricingRule does not exist (ModelNotFoundException)', function (): void {
    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'apply-test-3',
        'payload' => ['pricing_rule_id' => 999999, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'X'],
        'proposed_at' => now(),
    ]);

    expect(fn () => app(MarginChangeApplier::class)->apply($suggestion))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('apply() is idempotent — a second call with the SAME suggestion does NOT re-fire PricingRuleChanged', function (): void {
    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'apply-test-4',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'POP-SKU'],
        'proposed_at' => now(),
    ]);

    // First call — changes rule + fires event
    app(MarginChangeApplier::class)->apply($suggestion);
    expect($rule->fresh()->margin_basis_points)->toBe(7000);

    // Second call — observer's wasChanged guard prevents the re-fire
    Event::fake([PricingRuleChanged::class]);
    app(MarginChangeApplier::class)->apply($suggestion);

    Event::assertNotDispatched(PricingRuleChanged::class);
});

it('is registered in AppServiceProvider::boot for kind=margin_change', function (): void {
    $resolver = app(SuggestionApplierResolver::class);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'register-test',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7000],
        'evidence' => ['sku' => 'REG'],
        'proposed_at' => now(),
    ]);

    $applier = $resolver->resolve($suggestion);

    expect($applier)->toBeInstanceOf(MarginChangeApplier::class);
    expect($applier)->toBeInstanceOf(SuggestionApplier::class);
});

it('Auditor records competitor.margin_change_applied with before/after context', function (): void {
    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);

    $suggestion = Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'audit-test',
        'payload' => ['pricing_rule_id' => $rule->id, 'new_margin_basis_points' => 7500],
        'evidence' => ['sku' => 'AUDIT'],
        'proposed_at' => now(),
    ]);

    $auditorSpy = \Mockery::spy(\App\Foundation\Audit\Services\Auditor::class);
    $applier = new MarginChangeApplier($auditorSpy);

    $applier->apply($suggestion);

    $auditorSpy->shouldHaveReceived('record')
        ->with('competitor.margin_change_applied', \Mockery::on(function (array $ctx) use ($suggestion, $rule) {
            return ($ctx['suggestion_id'] ?? null) === $suggestion->id
                && ($ctx['pricing_rule_id'] ?? null) === $rule->id
                && ($ctx['old_margin_bps'] ?? null) === 5000
                && ($ctx['new_margin_bps'] ?? null) === 7500;
        }))
        ->once();
});
