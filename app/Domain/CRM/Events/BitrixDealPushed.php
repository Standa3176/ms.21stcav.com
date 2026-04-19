<?php

declare(strict_types=1);

namespace App\Domain\CRM\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 4 Plan 03 — fires after a Deal is created or updated in Bitrix.
 *
 * Modes:
 *   - 'created'      — new crm.deal.add call
 *   - 'adopted'      — UF_CRM_WOO_ORDER_ID filter adopted an existing Deal
 *   - 'updated'      — order.updated narrow-patch fired (notes + map refresh)
 *   - 'stage_changed'— UpdateDealStageJob fired a STAGE_ID patch
 *   - 'shadow'       — write happened in shadow-mode (sentinel returned)
 *
 * Phase 7 dashboard subscribers will consume these to render the CRM push
 * timeline. No listener in Plan 04-03.
 */
final class BitrixDealPushed extends DomainEvent
{
    public function __construct(
        public readonly int $wooOrderId,
        public readonly string $bitrixDealId,
        public readonly string $mode,
    ) {
        parent::__construct();
    }
}
