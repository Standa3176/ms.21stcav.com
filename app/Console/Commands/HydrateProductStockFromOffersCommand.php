<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\ProductSupplierBlockResolver;
use App\Domain\Sync\Services\SupplierCostOutlierGuard;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260611-qcq — products:hydrate-stock-from-offers.
 *
 * Closes the missing supplier_offer_snapshots → products.stock_quantity step
 * in the daily data flow.
 *
 * Pipeline:
 *   1. Supplier feeds   → supplier_db (external)                ✅
 *   2. supplier_db      → supplier_offer_snapshots (260609-rie) ✅
 *   3. supplier_offer_snapshots → products.stock_quantity       ← THIS COMMAND
 *   4. products         → Woo (260611-g4q push-divergence)      ✅
 *
 * **Proven by prod probe 2026-06-11:** SKU HA310-2EP had Ingram stock=5,659 in
 * supplier_offer_snapshots but products.stock_quantity=0. The push-divergence
 * step was therefore propagating phantom OOS to the storefront for a SKU where
 * the cheapest fresh supplier actually had 5.6k units of drop-ship stock.
 *
 * **Best-offer pick rule** — mirrors SupplierDbSyncCommand::buildBestOfferMap:
 *   cheapest FRESH (per SupplierFreshnessResolver) supplier_offer_snapshots
 *   row with stock > 0 per SKU. ONE freshness rule, TWO consumers — never
 *   duplicate the predicate; IMPORT the resolver via constructor DI.
 *
 * **WHY buy_price is preserved when going OOS:** the last-known cost is still
 * the right number for margin math even when the SKU is out of stock right
 * now. Wiping buy_price on the OOS branch would tank the sell_price (margin
 * calc reads buy_price) and propagate £0.00 cost into the next undercut run.
 *
 * **WHY no Woo writes here:** composability + separation of concerns. The
 * push-divergence-to-woo (260611-g4q) and push-visibility-to-woo (260611-f1y)
 * commands handle storefront sync. An operator can run hydrate WITHOUT pushing
 * if they want to inspect MS state first. The test suite binds a throwing
 * WooClient stub as a permanent guard so a future regression cannot silently
 * gain a Woo call.
 *
 * **Empty fresh-set sentinel:** when zero suppliers are fresh, we render the
 * '__NO_FRESH_SUPPLIERS__' string in the whereIn predicate (mirrors
 * SupplierOfferSnapshot::scopeFreshOnly's empty-whereIn safety). The 18-char
 * sentinel never matches a real supplier_id (numeric in the supplier feed).
 *
 * **Per-product DB::transaction:** a column-level failure on ONE product rolls
 * back THAT product, batch continues for siblings, errors counter increments.
 *
 * **Follow-up:** ProductOverride has no `pin_stock` column today (260611-qcq
 * planner finding). If operator override semantics are ever needed for stock
 * (e.g. "lock this SKU at qty=0 even if Ingram reports 200"), add the column
 * + respect it here BEFORE the writes branch.
 *
 *   php artisan products:hydrate-stock-from-offers --dry-run --limit=5
 *   php artisan products:hydrate-stock-from-offers --skus=HA310-2EP
 *   php artisan products:hydrate-stock-from-offers --only-stale=24
 *   php artisan products:hydrate-stock-from-offers --only-stale=0      # full-catalogue
 */
// Not `final` so the Pest feature test can swap SupplierFreshnessResolver
// through the container without subclassing the command itself (mirrors
// PushDivergenceToWooCommand / PushVisibilityToWooCommand).
class HydrateProductStockFromOffersCommand extends BaseCommand
{
    /**
     * Grep-discoverability anchor for the source-table — if the snapshot table
     * is ever renamed, the rename sweep finds THIS constant first.
     */
    private const SOURCE_TABLE = 'supplier_offer_snapshots';

    /**
     * Mirrors SupplierOfferSnapshot::scopeFreshOnly's empty-whereIn safety
     * pattern. 18-char string never matches a real numeric supplier_id.
     */
    private const NO_FRESH_SUPPLIERS_SENTINEL = '__NO_FRESH_SUPPLIERS__';

    protected $signature = 'products:hydrate-stock-from-offers
        {--skus= : Comma-separated SKU list; default = all live publish products with woo_product_id}
        {--limit=0 : Cap product count (0=unbounded)}
        {--only-stale=24 : Skip products whose last_synced_at is younger than N hours; 0 disables the freshness gate}
        {--dry-run : Print plan + sample table without writing}
        {--chunk=500 : Cursor chunk size (memory tuning, NOT a Woo throttle — no Woo calls)}';

    protected $description = 'Hydrate products.stock_quantity / stock_status / buy_price from supplier_offer_snapshots (cheapest fresh in-stock supplier per SKU). MS-side only — no Woo writes (260611-qcq).';

    public function __construct(private readonly SupplierFreshnessResolver $freshness)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $skusRaw = $this->option('skus');
        $limit = max(0, (int) $this->option('limit'));
        $staleHours = max(0, (int) $this->option('only-stale'));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        // ── 2. Resolve fresh supplier ids ────────────────────────────────────
        $freshIds = $this->freshness->freshSupplierIds()->all();
        $freshIdsForQuery = $freshIds === []
            ? [self::NO_FRESH_SUPPLIERS_SENTINEL]
            : $freshIds;

        // ── 3. Build candidate Product query ─────────────────────────────────
        $q = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id');

        if (is_string($skusRaw) && $skusRaw !== '') {
            // Products.sku stores the canonical Woo case (mixed-case in places —
            // e.g. HA310-2EP). The supplier_offer_snapshots.sku is the
            // lowercase-trimmed matchKey form. Apply trim() but NOT strtolower()
            // here so we match the Product table as-stored. The snapshot lookup
            // inside the per-product loop applies strtolower() before keying
            // into supplier_offer_snapshots — keeping the two case rules
            // independent.
            $skuList = array_values(array_unique(array_filter(array_map(
                static fn (string $s): string => trim($s),
                explode(',', $skusRaw),
            ), static fn (string $s): bool => $s !== '')));

            if ($skuList === []) {
                $this->warn('No valid SKUs parsed from --skus — nothing to do.');

                return SymfonyCommand::SUCCESS;
            }

            $q->whereIn('sku', $skuList);
        }

        if ($staleHours > 0) {
            $cutoff = now()->subHours($staleHours);
            $q->where(function ($q) use ($cutoff): void {
                $q->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', $cutoff);
            });
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        // ── 4. Print plan header ─────────────────────────────────────────────
        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'products:hydrate-stock-from-offers — fresh_suppliers='
            .count($freshIds)
            .' only_stale_hours='.$staleHours
            .($limit > 0 ? ' limit='.$limit : '')
            .($skusRaw ? ' --skus='.$skusRaw : '')
        );

        // ── 5. Counters + sample buffer ──────────────────────────────────────
        $scanned = 0;
        $updatedInStock = 0;
        $updatedOutOfStock = 0;
        $unchanged = 0;
        $errors = 0;
        $heldOutliers = 0;
        /** @var array<int, array{0:string,1:int,2:int,3:string,4:string,5:string}> */
        $samples = [];

        // 261027-duz — offer blocks + cost outlier guard. Resolved here (not the
        // constructor) so the test's container swap of the freshness resolver
        // keeps working.
        $blocks = app(ProductSupplierBlockResolver::class);
        $blocks->forget();
        $guardEnabled = (bool) config('supplier.cost_outlier_guard.enabled', true);
        $guardRatio = (float) config('supplier.cost_outlier_guard.ratio', 3);

        // ── 6. Per-product loop ──────────────────────────────────────────────
        foreach ($q->cursor() as $product) {
            $scanned++;

            $key = strtolower(trim((string) $product->sku));
            if ($key === '') {
                $unchanged++;

                continue;
            }

            // 261027-duz — this command is the SECOND buy_price writer (07:20,
            // 20 minutes after supplier:db-sync). Operator-blocked (sku, supplier)
            // offers are dropped here too, or a blocked price would re-enter cost.
            $blocked = array_map('strval', array_keys($blocks->blocksFor($key)));

            // Cheapest fresh in-stock offer — mirrors buildBestOfferMap.
            $offerQuery = fn () => DB::table(self::SOURCE_TABLE)
                ->where('sku', $key)
                ->whereIn('supplier_id', $freshIdsForQuery)
                ->when($blocked !== [], fn ($q) => $q->whereNotIn('supplier_id', $blocked))
                ->where('stock', '>', 0)
                ->orderBy('price', 'asc')
                ->orderByDesc('recorded_at');

            $offer = $offerQuery()->first();

            // 261027-duz — cost outlier guard (same rule as buildBestOfferMap).
            // If the cheapest offer is < 1/ratio of the lower median of the other
            // suppliers, leave buy_price untouched and take stock from the
            // cheapest in-stock NON-outlier supplier. No ImportIssue here —
            // supplier:db-sync at 07:00 already raised it.
            $holdBuyPrice = false;
            if ($offer !== null && $guardEnabled) {
                $outlierSid = (string) $offer->supplier_id;
                $hit = SupplierCostOutlierGuard::detect(
                    $this->latestPricesBySupplier($key, $freshIdsForQuery, $blocked, $outlierSid, (float) $offer->price),
                    $outlierSid,
                    $guardRatio,
                );
                if ($hit !== null) {
                    $holdBuyPrice = true;
                    $heldOutliers++;
                    Log::info('hydrate_stock.cost_outlier_held', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'evidence' => $hit,
                    ]);
                    $offer = $offerQuery()->where('supplier_id', '!=', $outlierSid)->first();
                }
            }

            if ($offer !== null) {
                $newStockQty = (int) $offer->stock;
                $newStockStatus = 'instock';
                $newBuyPrice = $holdBuyPrice ? null : $offer->price; // null = leave buy_price untouched
                $outcome = 'in_stock';
            } else {
                $newStockQty = 0;
                $newStockStatus = 'outofstock';
                $newBuyPrice = null; // sentinel — leave buy_price column untouched
                $outcome = 'out_of_stock';
            }

            // Detect unchanged (skip the write + the counter bump).
            $currentQty = (int) ($product->stock_quantity ?? 0);
            $currentStatus = (string) ($product->stock_status ?? '');
            $currentBuyPrice = $product->buy_price === null ? null : (float) $product->buy_price;
            $proposedBuyPrice = $newBuyPrice === null ? null : (float) $newBuyPrice;

            $qtyChanged = $currentQty !== $newStockQty;
            $statusChanged = $currentStatus !== $newStockStatus;
            // buy_price only counts as "changed" when we'd actually write it
            // (in_stock branch). OOS branch preserves last-known cost → no diff.
            $buyChanged = $newBuyPrice !== null
                && ($currentBuyPrice === null || $currentBuyPrice !== $proposedBuyPrice);

            if (! $qtyChanged && ! $statusChanged && ! $buyChanged) {
                $unchanged++;

                continue;
            }

            // Dry-run: capture up to 20 sample rows; do not write.
            if ($dryRun) {
                if (count($samples) < 20) {
                    $samples[] = [
                        (string) $product->sku,
                        $currentQty,
                        $newStockQty,
                        $currentStatus,
                        $newStockStatus,
                        $outcome,
                    ];
                }
                if ($outcome === 'in_stock') {
                    $updatedInStock++;
                } else {
                    $updatedOutOfStock++;
                }

                continue;
            }

            // Live write — per-product transaction for partial-failure safety.
            try {
                DB::transaction(function () use ($product, $newStockQty, $newStockStatus, $newBuyPrice): void {
                    $updates = [
                        'stock_quantity' => $newStockQty,
                        'stock_status' => $newStockStatus,
                        'last_synced_at' => now(),
                    ];
                    if ($newBuyPrice !== null) {
                        $updates['buy_price'] = $newBuyPrice;
                    }
                    Product::where('id', $product->id)->update($updates);
                });

                if ($outcome === 'in_stock') {
                    $updatedInStock++;
                } else {
                    $updatedOutOfStock++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('hydrate_stock_from_offers.row_failed', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'error' => $e->getMessage(),
                ]);
            }

            unset($chunkSize); // chunkSize is a forward-compat lever; cursor() handles streaming.
        }

        // ── 7. Output ────────────────────────────────────────────────────────
        if ($dryRun && $samples !== []) {
            $this->newLine();
            $this->table(
                ['sku', 'current_qty', 'proposed_qty', 'current_status', 'proposed_status', 'outcome'],
                $samples,
            );
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['scanned', $scanned],
                ['updated_in_stock', $updatedInStock],
                ['updated_out_of_stock', $updatedOutOfStock],
                ['unchanged', $unchanged],
                ['errors', $errors],
                ['cost_outlier_held', $heldOutliers],
            ],
        );

        return SymfonyCommand::SUCCESS;
    }

    /**
     * 261027-duz — lowest positive price per supplier for a key, from fresh, IN-STOCK,
     * non-blocked snapshot rows at that key's latest recorded_at. The chosen
     * offer's own price is folded in so the guard always evaluates the price
     * that would actually be written.
     *
     * @param  array<int, string>  $freshIds
     * @param  array<int, string>  $blocked
     * @return array<string, float>
     */
    private function latestPricesBySupplier(string $key, array $freshIds, array $blocked, string $chosenSid, float $chosenPrice): array
    {
        $base = fn () => DB::table(self::SOURCE_TABLE)
            ->where('sku', $key)
            ->whereIn('supplier_id', $freshIds)
            ->when($blocked !== [], fn ($q) => $q->whereNotIn('supplier_id', $blocked))
            ->where('stock', '>', 0) // same pool as the offer pick (in-stock only)
            ->where('price', '>', 0);

        $latest = $base()->max('recorded_at');

        $prices = [];
        if ($latest !== null) {
            foreach ($base()->where('recorded_at', $latest)->get(['supplier_id', 'price']) as $r) {
                $sid = (string) $r->supplier_id;
                $p = (float) $r->price;
                if (! isset($prices[$sid]) || $p < $prices[$sid]) {
                    $prices[$sid] = $p;
                }
            }
        }

        if ($chosenPrice > 0 && (! isset($prices[$chosenSid]) || $chosenPrice < $prices[$chosenSid])) {
            $prices[$chosenSid] = $chosenPrice;
        }

        return $prices;
    }
}
