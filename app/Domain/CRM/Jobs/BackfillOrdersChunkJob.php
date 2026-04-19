<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BackfillProgressTracker;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 4 Plan 05 Task 1 — process a chunk of Woo orders for the backfill.
 *
 * Three modes (passed via constructor):
 *   - 'dry-run'              : forces CRM_WRITE_ENABLED=false inside this worker;
 *                              PushOrderToBitrixJob's BitrixClient calls divert
 *                              into sync_diffs rows via shadowIfDisabled().
 *   - 'live'                 : relies on the globally-configured
 *                              CRM_WRITE_ENABLED; BitrixEntityMap UNIQUE
 *                              (entity_type, woo_id) guarantees re-runs are
 *                              idempotent.
 *   - 'adopt-legacy-deal-ids': NOT full push path. For each order with
 *                              meta_data[] containing _wc_bitrix24_deal_id,
 *                              call BitrixClient::dealUpdate($legacyId,
 *                              ['UF_CRM_WOO_ORDER_ID' => $orderId]) + write
 *                              BitrixEntityMap row with
 *                              created_via='adopted_legacy'. Pitfall 5.
 *
 * Runs on `sync-bulk` (NOT `crm-bitrix`) so a long backfill cannot starve
 * real-time webhook pushes (Pitfall 7).
 */
class BackfillOrdersChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $orderIds  Woo order IDs to process in this chunk.
     */
    public function __construct(
        public readonly array $orderIds,
        public readonly int $backfillRunId,
        public readonly string $mode,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function handle(
        WooClient $woo,
        BitrixClient $bitrix,
    ): void {
        $run = BitrixBackfillRun::find($this->backfillRunId);
        if ($run === null) {
            Log::warning('BackfillOrdersChunkJob: run not found', [
                'run_id' => $this->backfillRunId,
                'mode' => $this->mode,
            ]);

            return;
        }

        // Thread correlation_id onto Context for downstream logs.
        if ($this->correlationId !== null) {
            Context::add('correlation_id', $this->correlationId);
        }

        // Dry-run: force shadow mode for THIS worker process only.
        if ($this->mode === BitrixBackfillRun::MODE_DRY_RUN) {
            config(['services.bitrix.write_enabled' => false]);
        }

        $tracker = new BackfillProgressTracker($run);

        foreach ($this->orderIds as $orderId) {
            $orderId = (int) $orderId;

            try {
                $order = $woo->get('orders/'.$orderId);
            } catch (Throwable $e) {
                // Permanent fetch failure for a single order is a per-order failure,
                // NOT a chunk-level failure. Count + continue.
                $tracker->incrementFailed();
                Log::warning('BackfillOrdersChunkJob: woo get failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_array($order) || empty($order['id'])) {
                $tracker->incrementFailed();

                continue;
            }

            try {
                if ($this->mode === BitrixBackfillRun::MODE_ADOPT_LEGACY) {
                    $this->adoptLegacyDealId($order, $bitrix, $tracker);
                } else {
                    $this->dispatchPush($order, $tracker);
                }
            } catch (BitrixPermanentException $e) {
                $tracker->incrementFailed();
                Log::warning('BackfillOrdersChunkJob: permanent failure', [
                    'order_id' => $orderId,
                    'mode' => $this->mode,
                    'error' => $e->getMessage(),
                ]);
                // BitrixTransientException intentionally NOT caught — let the
                // chunk job itself retry per Horizon policy (tries=2).
            }

            $tracker->updateCursor((string) $orderId);
        }
    }

    /**
     * Pitfall 5 — adopt an existing Bitrix Deal created by the legacy
     * itgalaxy plugin by writing UF_CRM_WOO_ORDER_ID onto it + creating a
     * BitrixEntityMap row. Idempotent: already-mapped orders are skipped.
     */
    private function adoptLegacyDealId(array $order, BitrixClient $bitrix, BackfillProgressTracker $tracker): void
    {
        $orderId = (int) $order['id'];

        // Skip if already mapped (idempotent across re-runs).
        $existing = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
            ->where('woo_id', $orderId)
            ->first();
        if ($existing !== null) {
            $tracker->incrementSkipped();

            return;
        }

        // Find the legacy deal ID in meta_data[].
        $legacyDealId = null;
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if ($key === '_wc_bitrix24_deal_id') {
                $value = $meta['value'] ?? null;
                if ($value !== null && $value !== '') {
                    $legacyDealId = (string) $value;

                    break;
                }
            }
        }

        if ($legacyDealId === null) {
            // No legacy meta — nothing to adopt. Count as skipped.
            $tracker->incrementSkipped();

            return;
        }

        // Write UF_CRM_WOO_ORDER_ID onto the legacy Bitrix Deal.
        $bitrix->dealUpdate($legacyDealId, ['UF_CRM_WOO_ORDER_ID' => $orderId], $this->correlationId);

        BitrixEntityMap::create([
            'entity_type' => BitrixEntityMap::ENTITY_DEAL,
            'woo_id' => $orderId,
            'bitrix_id' => $legacyDealId,
            'last_status_snapshot' => (string) ($order['status'] ?? 'pending'),
            'last_correlation_id' => $this->correlationId,
            'last_pushed_at' => now(),
            'created_via' => BitrixEntityMap::VIA_ADOPTED_LEGACY,
        ]);

        $tracker->incrementAdoptedLegacy();
        $tracker->incrementProcessed();
    }

    /**
     * Dry-run + live modes both go through the same PushOrderToBitrixJob
     * handle() path. CRM_WRITE_ENABLED (dry-run forces it false) controls
     * whether the BitrixClient actually calls the SDK or diverts to
     * sync_diffs. Executed synchronously (dispatchSync) so a single worker
     * processes the chunk end-to-end without flooding the crm-bitrix queue.
     */
    private function dispatchPush(array $order, BackfillProgressTracker $tracker): void
    {
        $orderId = (int) $order['id'];

        // Fast-path idempotency: live mode re-runs short-circuit because the
        // existing BitrixEntityMap row routes PushOrderToBitrixJob down the
        // order.updated code path — which is safe to no-op if status hasn't
        // changed (it will refresh the map's payload_hash + skip stage
        // update). Tracker-level skip here avoids even the map-update write.
        if ($this->mode === BitrixBackfillRun::MODE_LIVE) {
            $existing = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
                ->where('woo_id', $orderId)
                ->first();
            if ($existing !== null) {
                $tracker->incrementSkipped();

                return;
            }
        }

        $receipt = WebhookReceipt::create([
            'source' => 'backfill-manual',
            'topic' => 'order.created',
            'delivery_id' => (string) Str::uuid(),
            'headers' => ['x-wc-webhook-topic' => ['order.created'], 'x-backfill-mode' => $this->mode],
            'raw_body' => (string) json_encode($order),
            'correlation_id' => $this->correlationId,
            'received_at' => now(),
            'status' => 'processed',
        ]);

        // Dispatch synchronously — the chunk job already runs on sync-bulk
        // with a long timeout; no need to push onto the crm-bitrix queue
        // and contend with live webhook traffic.
        PushOrderToBitrixJob::dispatchSync($receipt->id, 'order.created', 0);

        $tracker->incrementProcessed();
    }
}
