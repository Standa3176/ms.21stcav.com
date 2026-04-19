<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 5 Plan 03 Task 1 — A1 gate: event class + observer + wire-up shipped
 * this plan because Phase 3 didn't back-port the PricingRuleChanged signal.
 *
 * Fires from PricingRuleObserver::updated ONLY when margin_basis_points is
 * dirty after save. Downstream consumer: MarginChangeApplier (Task 3) calls
 * PricingRule::update(['margin_basis_points' => N]); the observer fires this
 * event so future Phase 3 listeners (e.g. a bulk RecomputeCatalogueJob
 * extension subscribing to rule changes) can react without the applier
 * knowing the full downstream dependency graph.
 *
 * Primitives only per T-03-05 mitigation — SerializesModels on DomainEvent
 * would leak hidden PricingRule columns on dispatch otherwise.
 *
 * correlation_id threads through the parent DomainEvent constructor so the
 * Suggestion approval → PricingRule update → PricingRuleChanged → (future
 * recompute listener) chain is all joinable on one CID in audit_log.
 */
final class PricingRuleChanged extends DomainEvent
{
    public function __construct(
        public readonly int $ruleId,
        public readonly int $oldMarginBps,
        public readonly int $newMarginBps,
    ) {
        parent::__construct();
    }
}
