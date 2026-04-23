<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 6 Plan 03 — fired by PublishProductJob when a draft flips to publish.
 *
 * Phase 7's dashboard tile "N products published this week" subscribes to this
 * event; no Phase 6 listener reads it. publishedByUserId is carried so the
 * dashboard can attribute the publish action in an audit view.
 */
final class ProductPublished extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly int $wooProductId,
        public readonly int $publishedByUserId,
    ) {
        parent::__construct();
    }
}
