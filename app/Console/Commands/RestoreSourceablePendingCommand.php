<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260713-rsp — products:restore-sourceable-pending.
 *
 * Cutover-prep LOCAL realignment. supplier:db-sync --flag-obsolete demotes
 * publish→pending any on-Woo product with NO fresh-supplier offer, and
 * products:flag-missing-buy-price demotes any publish product whose local
 * buy_price is null/≤0. Both demotions are LOCAL-only (they queue no Woo
 * write), so the product stays `publish` on the live store. A cutover audit
 * found on-Woo products that were demoted to pending yet DO have a current
 * in-stock supplier offer — a demotion that looks wrong. This command restores
 * those to `publish` LOCALLY so the local DB realigns with the store BEFORE
 * cutover (so the cutover status-push doesn't sweep sellable products off the
 * shop).
 *
 * CONSISTENT INVERSE — why this does NOT churn against the nightly sync:
 * flag-obsolete KEEPS a product published iff a fresh, non-excluded supplier
 * lists its SKU. This command restores using the SAME live signal
 * (LiveSupplierStockResolver → feeds_products + stockseparate, freshness-gated),
 * NOT supplier_sku_cache membership. supplier_sku_cache is BROADER than the
 * flag-obsolete keep-set — it is stock-agnostic and includes SKUs carried only
 * by stale / operator-excluded suppliers — so restoring on cache membership
 * would just be re-demoted on the next sync. Restoring on the live fresh-offer
 * signal survives the next flag-obsolete run.
 *
 *   default (strict):  restore only products with a CURRENT IN-STOCK offer
 *                      (resolveForSku, stock>0). db-sync also (re)writes their
 *                      buy_price on the next run, so they survive
 *                      flag-missing-buy-price too.
 *   --include-listed-out-of-stock:  also restore fresh-supplier-listed SKUs
 *                      (any stock) — the exact stock-agnostic keep-set of
 *                      flag-obsolete.
 *
 * Producer carve-outs honoured (never touched): is_custom_ms, the 'custom-ms'
 * tag, and exclude_from_auto_update — mirrors SupplierDbSyncCommand::
 * isObsoleteCandidate + FlagProductsMissingBuyPriceCommand.
 *
 * LOCAL-ONLY — this command MUST NOT call WooClient / push to Woo (the products
 * are already `publish` on Woo; realigning local status needs no write). The
 * feature test binds a throwing WooClient stub as a permanent guard.
 *
 * --dry-run is the DEFAULT (report + change nothing); --live applies. Idempotent
 * (only `status=pending` rows are candidates).
 *
 *   php artisan products:restore-sourceable-pending                             (dry-run — report the cohort)
 *   php artisan products:restore-sourceable-pending --live                      (restore in-stock cohort)
 *   php artisan products:restore-sourceable-pending --include-listed-out-of-stock  (dry-run, broader cohort)
 *   php artisan products:restore-sourceable-pending --include-listed-out-of-stock --live
 *
 * Not `final` so a future Pest feature test can swap collaborators through the
 * container without subclassing (mirrors HydrateLiveStockCommand).
 */
class RestoreSourceablePendingCommand extends BaseCommand
{
    protected $signature = 'products:restore-sourceable-pending
        {--live : Apply the restore (DEFAULT is dry-run — report only)}
        {--include-listed-out-of-stock : Also restore products merely LISTED by a fresh supplier (any stock), not only current in-stock offers}
        {--limit=0 : Cap the number of candidates scanned (0 = unbounded)}';

    protected $description = 'Restore wrongly-demoted pending + on-Woo products that have a CURRENT supplier offer back to publish LOCALLY (no Woo write). Dry-run by default; --live applies (260713-rsp).';

    public function __construct(private readonly LiveSupplierStockResolver $stock)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $includeListed = (bool) $this->option('include-listed-out-of-stock');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = ! $live;

        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'products:restore-sourceable-pending — '
            .($includeListed ? 'signal=fresh-listed (any stock)' : 'signal=in-stock (strict)')
            .($limit > 0 ? " limit={$limit}" : '')
        );

        // Candidate cohort: demoted-but-on-Woo. Only `pending` rows are ever a
        // restore candidate (idempotent — a restored `publish` row drops out).
        $q = Product::query()
            ->where('status', 'pending')
            ->whereNotNull('woo_product_id')
            ->orderBy('id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        $scanned = 0;
        $restored = 0;
        $skippedCarveOut = 0;
        $skippedNoSku = 0;
        $skippedNotSourceable = 0;
        /** @var array<int, array{0:string,1:string,2:string}> $samples */
        $samples = [];

        foreach ($q->cursor() as $product) {
            $scanned++;

            // Producer carve-outs — never realign an operator-managed product.
            if ($this->isCarveOut($product)) {
                $skippedCarveOut++;

                continue;
            }

            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                $skippedNoSku++;

                continue;
            }

            $reason = $this->restoreReason($sku, $includeListed);
            if ($reason === null) {
                $skippedNotSourceable++;

                continue;
            }

            if (count($samples) < 20) {
                $samples[] = [$sku, (string) $product->woo_product_id, $reason];
            }

            if (! $dryRun) {
                // LOCAL status realignment only — NO Woo write. update() (not a
                // model save) mirrors SupplierDbSyncCommand's demotion write so
                // the inverse is symmetric and fires no incidental side effects.
                Product::where('id', $product->id)->update(['status' => 'publish']);
            }
            $restored++;
        }

        if ($samples !== []) {
            $this->newLine();
            $this->table(['sku', 'woo_product_id', 'signal'], $samples);
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['scanned (pending + on-Woo)', $scanned],
                ['restored'.($dryRun ? ' (would)' : '').' → publish', $restored],
                ['skipped: carve-out (custom-ms / excluded)', $skippedCarveOut],
                ['skipped: no supplier offer'.($includeListed ? '' : ' / out-of-stock'), $skippedNotSourceable],
                ['skipped: blank sku', $skippedNoSku],
            ],
        );

        if ($dryRun && $restored > 0) {
            $this->newLine();
            $this->warn("Dry-run: {$restored} product(s) WOULD be restored to publish. Re-run with --live to apply.");
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Operator-managed products the supplier sync never demotes and this
     * command must never restore — mirrors SupplierDbSyncCommand::
     * isObsoleteCandidate + FlagProductsMissingBuyPriceCommand carve-outs.
     */
    private function isCarveOut(Product $product): bool
    {
        if ((bool) $product->is_custom_ms === true) {
            return true;
        }
        if ((bool) $product->exclude_from_auto_update === true) {
            return true;
        }

        return in_array('custom-ms', (array) ($product->tags ?? []), true);
    }

    /**
     * Returns the restore signal label ('in-stock' | 'listed') when the SKU
     * has a qualifying fresh-supplier offer, or null when it does not (so the
     * product stays demoted). Resolver is best-effort; a throw here can never
     * abort the batch.
     */
    private function restoreReason(string $sku, bool $includeListed): ?string
    {
        try {
            if ($this->stock->resolveForSku($sku) !== null) {
                return 'in-stock';
            }
            if ($includeListed && $this->stock->isListedByFreshSupplier($sku)) {
                return 'listed';
            }
        } catch (\Throwable $e) {
            Log::warning('restore_sourceable_pending.resolve_failed', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
