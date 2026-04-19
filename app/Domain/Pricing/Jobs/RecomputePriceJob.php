<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\Services\PriceRecomputer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3 Plan 04 Task 2 — per-SKU bulk-recompute work unit.
 *
 * Dispatched in a batch by `App\Domain\Pricing\Console\Commands\PricingRecomputeCommand`
 * so a 15k-SKU recompute becomes 15k isolated jobs on the `sync-bulk` queue.
 * Per-SKU failures do not mask the rest of the batch.
 *
 * Queue choice (Phase 1 D-09 + Pitfall 8): `sync-bulk`. This queue is
 * deliberately isolated from:
 *   - `default` (used by RecomputePriceListener — one-SKU-at-a-time event-driven)
 *   - `sync-woo-push` (reserved for the downstream Woo PUT emitted by the
 *     Phase 2 listener when ProductPriceChanged fires)
 *   - `webhook-inbound` (inbound HMAC-verified Woo webhooks)
 * Bulk recompute onto `sync-bulk` never starves the rate-limited Woo push
 * path or a synchronous webhook handler.
 *
 * ShouldBeUnique + uniqueFor = 300s (Pitfall 8):
 *   A stuck first batch + a manually re-run command MUST NOT double-dispatch
 *   the same SKU within the 5-minute window. `uniqueId()` keys on the Woo
 *   identity so parent vs variant of the same product are independently
 *   throttled.
 *
 * handle() delegates to PriceRecomputer::recompute — the shared core also
 * used by the event-driven listener (Plan 02). persist is forwarded from
 * the command flag (--live → true, --dry-run / default → false per D-12).
 */
class RecomputePriceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $sku,
        public readonly string $correlationId,
        public readonly bool $persist,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function uniqueId(): string
    {
        return 'recompute-price:'.$this->wooProductId.':'.($this->wooVariationId ?? 'parent');
    }

    public function handle(PriceRecomputer $recomputer): void
    {
        $recomputer->recompute(
            wooProductId: $this->wooProductId,
            wooVariationId: $this->wooVariationId,
            sku: $this->sku,
            correlationId: $this->correlationId,
            persist: $this->persist,
        );
    }
}
