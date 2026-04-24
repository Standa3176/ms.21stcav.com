<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 7 Plan 05 Task 1 — CUT-01 divergence scanner.
 *
 * For every local Product, calls WooClient::get('products', ['sku' => …]),
 * diffs the Woo-live dict vs the Laravel row via WooFieldComparator, and
 * (when --live is passed) writes one SyncDiff row PER FIELD DIFF with
 * provider='divergence-scan'.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * sync_diffs schema reality (Phase 1 Plan 04 + Phase 4 Plan 01 provider col):
 *   id, provider, channel, method, endpoint, woo_id, payload JSON,
 *   correlation_id, created_at, applied_at, status
 *
 * There is NO `product_id`, `field`, `laravel_value`, `live_value`, or
 * `detected_at` column on sync_diffs. This scanner therefore encodes the
 * field-level divergence context inside the `payload` JSON:
 *
 *   {
 *     "product_id":  123,
 *     "sku":         "ABC-001",
 *     "field":       "name",
 *     "laravel":     "Laravel Name",
 *     "live":        "Woo Name",
 *     "pin_column":  "pin_title"
 *   }
 *
 * and sets:
 *   - provider      = 'divergence-scan'
 *   - channel       = 'woo'
 *   - method        = 'GET'
 *   - endpoint      = "products?sku={sku}"
 *   - woo_id        = stringified woo_product_id (where known)
 *   - status        = 'pending'
 *   - correlation_id = single per-scan UUID (threads through all rows)
 *
 * This keeps Plan 07-02's SyncDiffsParityWidget happy (it counts
 * sync_diffs rows provider='divergence-scan' without inspecting payload
 * shape) while the downstream OverridePopulator reads the JSON field/pin_column
 * values to target specific ProductOverride columns.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Parity snapshot (--live only): after persisting diff rows, this service
 * writes dashboard_snapshots.sync_diffs_parity with the computed parity
 * percentage so the /admin widget flips to green once every Laravel row
 * matches Woo.
 */
class DivergenceScanner
{
    public const PROVIDER = 'divergence-scan';

    public function __construct(
        protected WooClient $woo,
        protected WooFieldComparator $comparator,
        protected Auditor $auditor,
    ) {}

    /**
     * Iterate every Product, diff against Woo, optionally persist.
     *
     * @param  bool  $writePersistent  When true, write SyncDiff rows + parity snapshot
     * @param  callable(int,string,string):void|null  $progress  Optional progress callback (scanned_count, sku, status)
     * @return array{scanned:int, divergedProducts:int, totalFieldDiffs:int, correlationId:string, parityPercent:?int}
     */
    public function scan(bool $writePersistent = false, ?callable $progress = null): array
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

        $scanned = 0;
        $divergedProducts = 0;
        $totalFieldDiffs = 0;

        Product::query()->cursor()->each(function (Product $product) use (
            &$scanned, &$divergedProducts, &$totalFieldDiffs,
            $writePersistent, $correlationId, $progress
        ) {
            $scanned++;
            $sku = $product->sku;
            if ($sku === null || $sku === '') {
                // Variable-product parent with null SKU — skip (children carry SKUs).
                $progress && $progress($scanned, '(null-sku)', 'skipped');

                return;
            }

            try {
                $response = $this->woo->get('products', ['sku' => $sku]);
                $wooProduct = $response[0] ?? null;
            } catch (\Throwable $e) {
                Log::warning('DivergenceScanner: Woo GET failed', [
                    'sku' => $sku,
                    'exception' => $e->getMessage(),
                ]);
                $progress && $progress($scanned, $sku, 'error');

                return;
            }

            $diffs = $this->comparator->diff($product, $wooProduct);
            if ($diffs !== []) {
                $divergedProducts++;
                $totalFieldDiffs += count($diffs);

                if ($writePersistent) {
                    foreach ($diffs as $d) {
                        SyncDiff::create([
                            'provider' => self::PROVIDER,
                            'channel' => 'woo',
                            'method' => 'GET',
                            'endpoint' => 'products?sku='.$sku,
                            'woo_id' => $product->woo_product_id !== null
                                ? (string) $product->woo_product_id
                                : null,
                            'payload' => [
                                'product_id' => $product->id,
                                'sku' => $sku,
                                'field' => $d['field'],
                                'laravel' => $this->scalarise($d['laravel']),
                                'live' => $this->scalarise($d['live']),
                                'pin_column' => $d['pin_column'],
                            ],
                            'correlation_id' => $correlationId,
                            'created_at' => now(),
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            $progress && $progress($scanned, $sku, 'done');
        });

        $parityPercent = null;
        if ($writePersistent) {
            $parityPercent = $scanned === 0
                ? null
                : (int) round(100 - min(100, ($divergedProducts / max(1, $scanned)) * 100));

            // CUT-01 dashboard-widget feed. Matches the shape SnapshotAggregator
            // writes via computeSyncDiffsParity (Plan 07-02) — widget reads are
            // agnostic to which writer populated the row.
            $threshold = (int) config('cutover.parity_threshold_percent', 99);
            DashboardSnapshot::upsertByKey('sync_diffs_parity', [
                'parity_percent' => $parityPercent,
                'diverged_rows' => $totalFieldDiffs,
                'total_products' => $scanned,
                'threshold_percent' => $threshold,
                'traffic_light' => $parityPercent === null
                    ? 'amber'
                    : ($parityPercent >= $threshold ? 'green' : 'red'),
                'window_days' => (int) config('cutover.parity_window_days', 7),
                'source' => 'cutover:divergence-scan',
                'correlation_id' => $correlationId,
            ]);

            $this->auditor->record('cutover.divergence_scan_completed', [
                'scanned' => $scanned,
                'diverged_products' => $divergedProducts,
                'total_field_diffs' => $totalFieldDiffs,
                'parity_percent' => $parityPercent,
                'correlation_id' => $correlationId,
            ]);
        }

        return [
            'scanned' => $scanned,
            'divergedProducts' => $divergedProducts,
            'totalFieldDiffs' => $totalFieldDiffs,
            'correlationId' => $correlationId,
            'parityPercent' => $parityPercent,
        ];
    }

    /**
     * Coerce a value to something JSON-safe + human-readable for sync_diffs payload.
     */
    protected function scalarise(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_encode($value);
    }
}
