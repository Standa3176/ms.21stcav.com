<?php

declare(strict_types=1);

namespace App\Domain\Products\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Quick task 260611-s2d — emitted by ProductObserver::updated when one of
 * the tracked fields (stock_quantity / buy_price / sell_price / category_id)
 * changes on a Product instance ->save().
 *
 * Listener: PushProductFieldsToWoo (sync-woo-push queue).
 *
 * Flag-gated via cutover.event_driven_push_enabled (default false) at the
 * observer dispatch boundary — when the flag is OFF the event NEVER fires,
 * so subscribers + queue stay quiet on the no-op path.
 *
 * Extends DomainEvent for correlation_id auto-population from the Context
 * facade (matches ProductPriceChanged convention; survives HTTP→queue boundary
 * via the Laravel 12 Context dehydrate/hydrate). T-03-05 compliance: only
 * primitive props (productId / sku / changedFields[]), NO full Eloquent
 * models — SerializesModels would leak hidden columns on dispatch.
 *
 * ShouldDispatchAfterCommit semantics inherited from DomainEvent: events
 * dispatched inside a DB::transaction() that rolls back do NOT fire
 * listeners. Outside transactions the dispatch is immediate (per the
 * observer's Eloquent-updated hook timing).
 */
final class ProductFieldsChangedEvent extends DomainEvent
{
    /**
     * @param  array<int, string>  $changedFields  subset of {stock_quantity, buy_price, sell_price, category_id}
     */
    public function __construct(
        public readonly int $productId,
        public readonly ?string $sku,
        public readonly array $changedFields,
    ) {
        parent::__construct();
    }
}
