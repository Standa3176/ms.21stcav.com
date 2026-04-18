<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Fired when a Woo customer webhook is received + receipt persisted.
 *
 * Phase 4 CRM module subscribes to upsert Bitrix24 contact.
 */
final class CustomerRegistered extends DomainEvent
{
    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $deliveryId,
    ) {
        parent::__construct();
    }
}
