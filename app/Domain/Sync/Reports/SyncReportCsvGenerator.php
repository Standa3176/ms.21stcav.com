<?php

declare(strict_types=1);

namespace App\Domain\Sync\Reports;

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use Illuminate\Support\Facades\File;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Phase 2 Plan 02-04 — D-10 11-column CSV report writer.
 *
 * Streams SyncRunItem rows for a given SyncRun into storage/app/private/sync-reports/
 * via spatie/simple-excel's generator pattern (constant memory — Pitfall P2-A).
 *
 * Columns (exact order per D-10):
 *   sku, woo_product_id, woo_variation_id, action, reason,
 *   old_price, new_price, old_stock, new_stock,
 *   error_message, correlation_id
 *
 * Pitfall P2-A mitigation: SimpleExcelWriter flushes its internal buffer on
 * __destruct. We unset() the writer before returning the path so Mail::attach()
 * reads a fully-flushed file (otherwise partial row data can land in the
 * attachment when the Mailable sends before PHP garbage-collects the writer).
 */
final class SyncReportCsvGenerator
{
    /**
     * Generate the CSV for a completed or aborted SyncRun.
     *
     * Returns the absolute path to the written CSV file.
     */
    public function generate(SyncRun $run): string
    {
        $path = storage_path("app/private/sync-reports/run-{$run->id}.csv");
        File::ensureDirectoryExists(dirname($path));

        // ->noHeaderRow() so we write the D-10 header explicitly as the first addRow()
        // call — lets the header appear even when the run has zero items.
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();

        $writer->addRow([
            'sku',
            'woo_product_id',
            'woo_variation_id',
            'action',
            'reason',
            'old_price',
            'new_price',
            'old_stock',
            'new_stock',
            'error_message',
            'correlation_id',
        ]);

        // Chunk to keep memory constant even for 15k-SKU runs.
        SyncRunItem::forRun($run->id)->orderBy('id')->chunk(500, function ($chunk) use ($writer) {
            foreach ($chunk as $item) {
                $writer->addRow([
                    $item->sku,
                    $item->woo_product_id,
                    $item->woo_variation_id,
                    $item->action,
                    $item->reason,
                    $item->old_price,
                    $item->new_price,
                    $item->old_stock,
                    $item->new_stock,
                    $item->error_message,
                    $item->correlation_id,
                ]);
            }
        });

        // Pitfall P2-A: force-flush by releasing the writer BEFORE returning.
        // Without this, Mail::attach() on an async queue could read a partial file.
        unset($writer);

        return $path;
    }
}
