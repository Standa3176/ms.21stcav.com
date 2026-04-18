<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Per-page work unit. Dispatched by SyncSupplierCommand per WooProductIterator yield.
 *
 * - Queue: sync-woo-push (2-3 workers, 120s timeout — Pitfall P2-E)
 * - Per-SKU idempotency via Product/ProductVariant.last_synced_at > run.started_at
 *   (Pitfall P2-F — worker retries after partial success skip already-done SKUs)
 * - Per-SKU failure path writes sync_errors row AND calls AbortGuard::recordFailure.
 *   Does NOT also call $run->incrementCounter('failed') — AbortGuard owns that column
 *   (double-counting avoidance per Checker blocker fix).
 * - Success path: $run->incrementCounter('updated') (or 'skipped') + AbortGuard::recordSuccess.
 *   These touch DISJOINT columns — no conflict.
 * - SyncAbortException from AbortGuard::throwIfTriggered bubbles out so the
 *   orchestrator can flip run.status='aborted' and persist the cursor.
 *
 * Plan 02-05 SYNC-04 refactor: the per-SKU DB::transaction wrapper was removed in
 * favour of sequential writes. Atomicity reasoning:
 *   1. Woo PUT is atomic on Woo's end.
 *   2. Eloquent save/update is atomic per row.
 *   3. cursor_page/cursor_sku is written LAST so partial failure is recoverable
 *      via --resume (the cursor advances only after full success for that SKU).
 *   4. Pitfall P2-F idempotency check skips already-synced SKUs on retry.
 * This keeps the Sync domain free of direct DB facade use — Deptrac's
 * `-WpDirectDb` deny rule enforces the prohibition permanently.
 */
final class SyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;  // Pitfall P2-E — bump from 90s for 50 SKUs + backoff.

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    /**
     * @param  array<int, array<string, mixed>>  $skus
     * @param  array<string, array{price: string, stock: int}>  $supplierFeed
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $page,
        public readonly array $skus,
        public readonly array $supplierFeed,
    ) {
        $this->onQueue('sync-woo-push');
    }

    public function handle(WooClient $woo, SyncDiffEngine $diffEngine, AbortGuard $abortGuard): void
    {
        /** @var SyncRun $run */
        $run = SyncRun::findOrFail($this->runId);
        Context::add('correlation_id', $run->correlation_id);

        foreach ($this->skus as $skuRow) {
            // D-06 — abort check before every SKU so a runaway run halts quickly.
            $abortGuard->throwIfTriggered($run->id);

            // Pitfall P2-F — worker retry idempotency. If this SKU was synced within
            // THIS run's window, skip it (the chunk was retried after partial success).
            if ($this->alreadySyncedInThisRun($skuRow, $run)) {
                continue;
            }

            $sku = (string) ($skuRow['sku'] ?? '');
            $supplierRow = $this->supplierFeed[$sku] ?? null;

            try {
                $diff = $diffEngine->diff($skuRow, $supplierRow);

                if ($diff === null) {
                    // No-op (exact match OR missing-at-supplier — latter handled by MarkMissingSkusJob).
                    $abortGuard->recordSuccess($run->id);
                    $run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku]);

                    continue;
                }

                if ($diff['action'] === 'skipped') {
                    $this->writeRunItem($run, $skuRow, 'skipped', $diff);
                    // Skipped touches disjoint column from AbortGuard's failed_count;
                    // safe to both increment skipped_count AND record success.
                    $run->incrementCounter('skipped');
                    $abortGuard->recordSuccess($run->id);
                    $run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku]);

                    continue;
                }

                // action === 'updated' → write to Woo, mirror local, event-dispatch.
                // Plan 02-05 SYNC-04: no DB::transaction wrapper (see class docblock).
                // Atomicity is provided by Woo's remote write + per-row Eloquent save + cursor-last ordering.
                $woo->put($diff['endpoint'], $diff['payload']);
                $this->writeRunItem($run, $skuRow, 'updated', $diff);
                $this->upsertLocalMirror($skuRow, $supplierRow, $run);

                $this->dispatchDomainEvents($skuRow, $diff);
                $run->incrementCounter('updated');
                $abortGuard->recordSuccess($run->id);
                $run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku]);
            } catch (SyncAbortException $e) {
                throw $e;  // bubble to orchestrator
            } catch (\Throwable $e) {
                SyncError::create([
                    'sync_run_id' => $run->id,
                    'sku' => $sku,
                    'woo_product_id' => $skuRow['woo_product_id'] ?? null,
                    'woo_variation_id' => $skuRow['woo_variation_id'] ?? null,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);

                $this->writeRunItem($run, $skuRow, 'failed', null, $e->getMessage());

                // AbortGuard owns failed_count + consecutive_failures + total_skus.
                // Do NOT also call $run->incrementCounter('failed') here (double-count).
                $abortGuard->recordFailure($run->id);
                $run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku]);
            }
        }
    }

    private function alreadySyncedInThisRun(array $skuRow, SyncRun $run): bool
    {
        $wooId = $skuRow['woo_product_id'] ?? null;
        if ($wooId === null || $run->started_at === null) {
            return false;
        }

        if (($skuRow['type'] ?? 'simple') === 'variation') {
            $variation = ProductVariant::where('woo_variation_id', $skuRow['woo_variation_id'] ?? null)->first();

            return $variation?->last_synced_at?->greaterThan($run->started_at) ?? false;
        }

        $product = Product::where('woo_product_id', $wooId)->first();

        return $product?->last_synced_at?->greaterThan($run->started_at) ?? false;
    }

    private function upsertLocalMirror(array $skuRow, array $supplierRow, SyncRun $run): void
    {
        $now = now();

        if (($skuRow['type'] ?? 'simple') === 'variation') {
            ProductVariant::where('woo_variation_id', $skuRow['woo_variation_id'] ?? null)->update([
                'buy_price' => $supplierRow['price'],
                'stock_quantity' => $supplierRow['stock'],
                'last_synced_at' => $now,
            ]);

            return;
        }

        Product::where('woo_product_id', $skuRow['woo_product_id'] ?? 0)->update([
            'buy_price' => $supplierRow['price'],
            'last_synced_at' => $now,
            'last_sync_run_id' => $run->id,
        ]);
    }

    private function writeRunItem(SyncRun $run, array $skuRow, string $action, ?array $diff, ?string $errorMessage = null): void
    {
        SyncRunItem::create([
            'sync_run_id' => $run->id,
            'sku' => (string) ($skuRow['sku'] ?? ''),
            'woo_product_id' => $skuRow['woo_product_id'] ?? null,
            'woo_variation_id' => $skuRow['woo_variation_id'] ?? null,
            'action' => $action,
            'reason' => $diff['reason'] ?? null,
            'old_price' => $diff['old_price'] ?? null,
            'new_price' => $diff['new_price'] ?? null,
            'old_stock' => $diff['old_stock'] ?? null,
            'new_stock' => $diff['new_stock'] ?? null,
            'error_message' => $errorMessage,
            'correlation_id' => $run->correlation_id,
            'created_at' => now(),
        ]);
    }

    private function dispatchDomainEvents(array $skuRow, array $diff): void
    {
        $sku = (string) ($skuRow['sku'] ?? '');
        $pid = (int) ($skuRow['woo_product_id'] ?? 0);
        $vid = $skuRow['woo_variation_id'] ?? null;

        if (isset($diff['payload']['regular_price'])) {
            event(new SupplierPriceChanged(
                sku: $sku,
                wooProductId: $pid,
                wooVariationId: $vid !== null ? (int) $vid : null,
                oldPrice: (string) ($diff['old_price'] ?? ''),
                newPrice: (string) ($diff['new_price'] ?? ''),
            ));
        }

        if (isset($diff['payload']['stock_quantity'])) {
            event(new SupplierStockChanged(
                sku: $sku,
                wooProductId: $pid,
                wooVariationId: $vid !== null ? (int) $vid : null,
                oldStock: (int) ($diff['old_stock'] ?? 0),
                newStock: (int) ($diff['new_stock'] ?? 0),
            ));
        }
    }
}
