<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Fired when a Woo order webhook is received + receipt persisted.
 *
 * Phase 4 CRM module listener (PushDealToBitrixJob) subscribes.
 * Phase 1 ships no listener — event fires into the void, which is fine.
 *
 * Payload = webhook_receipts.id; listener loads the row to access raw_body.
 */
final class OrderReceived extends DomainEvent
{
    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $deliveryId,
    ) {
        parent::__construct(); // auto-populates correlationId + occurredAt
    }
}
