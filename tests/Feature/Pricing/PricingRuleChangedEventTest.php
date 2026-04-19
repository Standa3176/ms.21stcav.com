<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\PricingRuleChanged;
use App\Domain\Pricing\Models\PricingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 1 — PricingRuleChanged event + PricingRuleObserver
|--------------------------------------------------------------------------
|
| A1 gate verification: the event class + observer are SHIPPED in this plan
| because Phase 3 didn't back-port them. Downstream MarginChangeApplier
| triggers this event via $rule->update(['margin_basis_points' => X]) — the
| observer only fires when margin is dirty (defensive no-op on other edits).
|
| Extends DomainEvent (auto-fills correlationId + occurredAt) and carries
| primitives only per T-03-05 mitigation.
*/

it('carries ruleId, oldMarginBps, newMarginBps and inherits correlationId from DomainEvent', function (): void {
    $event = new PricingRuleChanged(ruleId: 1, oldMarginBps: 5000, newMarginBps: 6000);

    expect($event->ruleId)->toBe(1);
    expect($event->oldMarginBps)->toBe(5000);
    expect($event->newMarginBps)->toBe(6000);
    expect($event->correlationId)->toBeString();
    expect(strlen($event->correlationId))->toBeGreaterThan(0);
    expect($event->occurredAt)->toBeString();
});

it('observer fires PricingRuleChanged when margin_basis_points is dirty after save', function (): void {
    Event::fake([PricingRuleChanged::class]);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    $rule->update(['margin_basis_points' => 6000]);

    Event::assertDispatched(PricingRuleChanged::class, function (PricingRuleChanged $event) use ($rule) {
        return $event->ruleId === $rule->id
            && $event->oldMarginBps === 5000
            && $event->newMarginBps === 6000;
    });
});

it('observer does NOT fire PricingRuleChanged when only priority changes (no margin dirty)', function (): void {
    Event::fake([PricingRuleChanged::class]);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000, 'priority' => 100]);
    $rule->update(['priority' => 200]);

    Event::assertNotDispatched(PricingRuleChanged::class);
});

it('observer does NOT fire on initial create (only on update)', function (): void {
    Event::fake([PricingRuleChanged::class]);

    PricingRule::factory()->create(['margin_basis_points' => 5000]);

    Event::assertNotDispatched(PricingRuleChanged::class);
});

it('observer does NOT fire when margin_basis_points is re-saved unchanged', function (): void {
    Event::fake([PricingRuleChanged::class]);

    $rule = PricingRule::factory()->create(['margin_basis_points' => 5000]);
    $rule->update(['margin_basis_points' => 5000]);

    Event::assertNotDispatched(PricingRuleChanged::class);
});
