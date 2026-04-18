<?php

declare(strict_types=1);

namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * SYNC-06 + D-03 — flip Woo status for SKUs absent from the supplier feed.
 *
 * Runs AFTER all SyncChunkJobs. Receives the (inWoo − inSupplier) set from the
 * orchestrator, each row carrying the Woo-side price + stock so SyncRunItem rows
 * can populate all 11 D-10 CSV columns even for the 'missing' action.
 *
 * Missing handling (D-03 granular):
 *   - simple without custom-ms → status='pending' (Woo write)
 *   - simple WITH custom-ms    → status stays 'publish' (NO Woo write, SupplierSkuMissing still dispatched)
 *   - variation (regardless of parent) → status='private' (Woo write)
 *
 * WooClient::put() already honours WOO_WRITE_ENABLED — no need to branch on
 * dry_run inside the job (belt-and-braces: the env gate is the actual write-guard).
 */
final class MarkMissingSkusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;  // May touch many SKUs sequentially.

    /**
     * @param  array<int, array{sku:string, type:string, woo_product_id:int, woo_variation_id:?int, is_custom_ms:bool, woo_price:string, woo_stock:int}>  $missingRows
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $missingRows,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function handle(WooClient $woo): void
    {
        /** @var SyncRun $run */
        $run = SyncRun::findOrFail($this->runId);
        Context::add('correlation_id', $run->correlation_id);

        foreach ($this->missingRows as $row) {
            [$newStatus, $endpoint, $shouldWrite] = $this->decideAction($row);

            try {
                if ($shouldWrite) {
                    $woo->put($endpoint, ['status' => $newStatus]);
                }

                ImportIssue::create([
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'],
                    'woo_variation_id' => $row['woo_variation_id'] ?? null,
                    'issue_type' => ImportIssue::TYPE_MISSING_AT_SUPPLIER,
                    'detected_at' => now(),
                    'last_seen_at' => now(),
                    'notes' => "Missing from supplier feed; newStatus={$newStatus}",
                    'correlation_id' => $run->correlation_id,
                ]);

                SyncRunItem::create([
                    'sync_run_id' => $run->id,
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'],
                    'woo_variation_id' => $row['woo_variation_id'] ?? null,
                    'action' => SyncRunItem::ACTION_MISSING,
                    'reason' => ($row['is_custom_ms'] ?? false)
                        ? 'missing_at_supplier_custom_ms_preserved'
                        : 'missing_at_supplier',
                    // D-10 CSV requires all 11 columns. Woo state IS known (the row
                    // exists in Woo), so populate old_price + old_stock from the
                    // Woo-side data passed through the orchestrator's missingRows.
                    'old_price' => $row['woo_price'] ?? null,
                    'new_price' => null,
                    'old_stock' => $row['woo_stock'] ?? null,
                    'new_stock' => null,
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);

                $run->incrementCounter('missing');

                event(new SupplierSkuMissing(
                    sku: $row['sku'],
                    wooProductId: $row['woo_product_id'],
                    wooVariationId: $row['woo_variation_id'] ?? null,
                    hadCustomMsTag: (bool) ($row['is_custom_ms'] ?? false),
                    newStatus: $newStatus,
                ));
            } catch (\Throwable $e) {
                // A single missing-SKU failure should not abort the pass; log and continue.
                SyncError::create([
                    'sync_run_id' => $run->id,
                    'sku' => $row['sku'],
                    'woo_product_id' => $row['woo_product_id'] ?? null,
                    'woo_variation_id' => $row['woo_variation_id'] ?? null,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: bool}  [newStatus, endpoint, shouldWrite]
     */
    private function decideAction(array $row): array
    {
        $type = $row['type'] ?? 'simple';

        if ($type === 'variation') {
            // D-03: variations flip to private regardless of parent's custom-ms tag.
            return [
                'private',
                "products/{$row['woo_product_id']}/variations/{$row['woo_variation_id']}",
                true,
            ];
        }

        // simple
        if (($row['is_custom_ms'] ?? false) === true) {
            return ['publish', '', false];  // D-03 carve-out — no flip.
        }

        return ['pending', "products/{$row['woo_product_id']}", true];
    }
}
