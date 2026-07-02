<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260702-pes — products:hydrate-live-stock.
 *
 * Operator backfill/repair tool that re-hydrates products.stock_quantity /
 * stock_status / buy_price from the LIVE cheapest-fresh-in-stock supplier offer
 * (LiveSupplierStockResolver → feeds_products + stockseparate, freshness-gated).
 *
 * WHY LIVE (not hydrate-stock-from-offers): a product created TODAY has no
 * supplier_offer_snapshots rows yet (the nightly sync only snapshots SKUs that
 * existed at sync time), so hydrate-stock-from-offers can't repair it. This
 * command reads feeds_products directly via the resolver, so today's already-
 * published batch (which went live OOS) can be repaired same-day.
 *
 * Targeting: default = published products with a woo_product_id created TODAY.
 * Override with --skus / --created-since / --only-null-qty / --limit.
 *
 * MS-side ONLY — NO Woo writes (mirrors products:hydrate-stock-from-offers's
 * 260611-qcq invariant). The operator pushes the repaired local stock to the
 * storefront with the existing products:backfill-woo-stock. The feature test
 * binds a throwing WooClient stub as a permanent guard so a future regression
 * cannot silently gain a Woo call.
 *
 *   php artisan products:hydrate-live-stock --dry-run                (preview today's batch)
 *   php artisan products:hydrate-live-stock                          (repair today's batch)
 *   php artisan products:hydrate-live-stock --skus=43376 --dry-run   (verify one SKU)
 *   php artisan products:hydrate-live-stock --created-since=2026-06-25
 */
// Not `final` so a future Pest feature test can swap collaborators through the
// container without subclassing the command (mirrors HydrateProductStockFromOffersCommand).
class HydrateLiveStockCommand extends BaseCommand
{
    protected $signature = 'products:hydrate-live-stock
        {--skus= : Comma-separated SKUs; overrides --created-since}
        {--created-since= : Only products created on/after this date (Y-m-d); default = today when no --skus}
        {--only-null-qty : Only products whose stock_quantity IS NULL}
        {--limit=0 : Cap product count (0=unbounded)}
        {--dry-run : Print plan + sample table without writing}';

    protected $description = 'Re-hydrate products.stock_quantity/stock_status/buy_price from the LIVE cheapest-fresh-in-stock supplier offer (feeds_products+stockseparate). MS-side only — no Woo writes; push with products:backfill-woo-stock (260702-pes).';

    public function __construct(private readonly LiveSupplierStockResolver $stock)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $skusRaw = $this->option('skus');
        $createdSinceRaw = $this->option('created-since');
        $onlyNullQty = (bool) $this->option('only-null-qty');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // ── 2. Build candidate query ─────────────────────────────────────────
        $q = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id');

        $scope = '';
        if (is_string($skusRaw) && $skusRaw !== '') {
            // Products.sku stores the canonical Woo case — trim() only (no
            // lowercase) so we match the Product table as-stored. The resolver
            // lowercases internally when keying into feeds_products.
            $skuList = array_values(array_unique(array_filter(array_map(
                static fn (string $s): string => trim($s),
                explode(',', $skusRaw),
            ), static fn (string $s): bool => $s !== '')));

            if ($skuList === []) {
                $this->warn('No valid SKUs parsed from --skus — nothing to do.');

                return SymfonyCommand::SUCCESS;
            }

            $q->whereIn('sku', $skuList);
            $scope = '--skus='.implode(',', $skuList);
        } else {
            // Default to today when no explicit --created-since.
            $date = is_string($createdSinceRaw) && $createdSinceRaw !== ''
                ? Carbon::parse($createdSinceRaw)->toDateString()
                : today()->toDateString();
            $q->whereDate('created_at', '>=', $date);
            $scope = 'created_since='.$date;
        }

        if ($onlyNullQty) {
            $q->whereNull('stock_quantity');
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        // ── 3. Plan header ───────────────────────────────────────────────────
        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'products:hydrate-live-stock — '.$scope
            .($onlyNullQty ? ' --only-null-qty' : '')
            .($limit > 0 ? ' limit='.$limit : '')
        );

        // ── 4. Counters + sample buffer ──────────────────────────────────────
        $scanned = 0;
        $updated = 0;
        $unchanged = 0;
        $noOffer = 0;
        /** @var array<int, array{0:string,1:string,2:string,3:string}> */
        $samples = [];

        // ── 5. Per-product loop ──────────────────────────────────────────────
        foreach ($q->cursor() as $product) {
            $scanned++;

            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                $unchanged++;

                continue;
            }

            $offer = null;
            try {
                $offer = $this->stock->resolveForSku($sku);
            } catch (\Throwable $e) {
                // Resolver is best-effort (returns null on failure) — this catch
                // is belt-and-braces so one SKU can never abort the batch.
                Log::warning('hydrate_live_stock.resolve_failed', [
                    'product_id' => $product->id, 'sku' => $sku, 'error' => $e->getMessage(),
                ]);
            }

            $currentQty = $product->stock_quantity;

            if ($offer === null) {
                $noOffer++;
                if (count($samples) < 20) {
                    $samples[] = [$sku, $currentQty === null ? '—' : (string) $currentQty, '—', 'no_offer'];
                }

                continue;
            }

            $newQty = $offer['stock_quantity'];
            $newStatus = $offer['stock_status'];
            $newBuy = $offer['buy_price'];

            // Detect no-op (skip the write + the counter bump).
            $currentStatus = (string) ($product->stock_status ?? '');
            $currentBuy = $product->buy_price === null ? null : (float) $product->buy_price;
            $qtyChanged = (int) ($currentQty ?? -1) !== $newQty || $currentQty === null;
            $statusChanged = $currentStatus !== $newStatus;
            $buyChanged = $newBuy !== null && ($currentBuy === null || $currentBuy !== $newBuy);

            if (! $qtyChanged && ! $statusChanged && ! $buyChanged) {
                $unchanged++;

                continue;
            }

            if (count($samples) < 20) {
                $samples[] = [$sku, $currentQty === null ? '—' : (string) $currentQty, (string) $newQty, 'updated'];
            }

            if ($dryRun) {
                $updated++;

                continue;
            }

            $updates = [
                'stock_quantity' => $newQty,
                'stock_status' => $newStatus,
                'last_synced_at' => now(),
            ];
            if ($newBuy !== null) {
                $updates['buy_price'] = $newBuy;
            }
            $product->forceFill($updates)->saveQuietly();
            $updated++;
        }

        // ── 6. Output ────────────────────────────────────────────────────────
        if ($samples !== []) {
            $this->newLine();
            $this->table(['sku', 'current_qty', 'new_qty', 'outcome'], $samples);
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['scanned', $scanned],
                ['updated'.($dryRun ? ' (would)' : ''), $updated],
                ['unchanged', $unchanged],
                ['no_offer', $noOffer],
            ],
        );

        return SymfonyCommand::SUCCESS;
    }
}
