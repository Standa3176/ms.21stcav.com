<?php

declare(strict_types=1);

namespace App\Domain\CRM\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 4 Plan 03 — fires after a Company upsert via EntityDeduper.
 *
 * Companies have no Woo primary key (woo_id=0 sentinel on BitrixEntityMap), so
 * the event carries the resolved Bitrix ID + the title the dedup key was built
 * from instead of a wooId.
 *
 * mode: 'upsert' | 'shadow'
 */
final class BitrixCompanyPushed extends DomainEvent
{
    public function __construct(
        public readonly string $companyTitle,
        public readonly string $bitrixCompanyId,
        public readonly string $mode,
    ) {
        parent::__construct();
    }
}
