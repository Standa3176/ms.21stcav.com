<?php

declare(strict_types=1);

namespace App\Domain\CRM\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 4 Plan 03 — fires after a Contact upsert completes via EntityDeduper.
 *
 * mode: 'upsert' (EntityDeduper's map/phone/email cascade resolved a Bitrix ID —
 * whether adopted or freshly created) or 'shadow' (write gated to sync_diffs).
 */
final class BitrixContactPushed extends DomainEvent
{
    public function __construct(
        public readonly int $wooCustomerId,
        public readonly string $bitrixContactId,
        public readonly string $mode,
    ) {
        parent::__construct();
    }
}
