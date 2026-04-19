<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4 Plan 03 Task 1 — skeleton (listeners can type-hint).
 * Task 2 replaces handle() + failed() with full EntityDeduper.findOrCreateContact
 * flow + D-04 Contact-level UTM capture + D-11 retry policy.
 */
class PushCustomerToBitrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 120;

    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $topic,
    ) {
        $this->onQueue('crm-bitrix');
    }

    public function handle(): void
    {
        // Replaced by Task 2 full implementation.
    }
}
