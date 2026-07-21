<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260713-rsp — products:restore-sourceable-pending.
 * Quick task 260721-apr — + --push-to-woo (closes the one-way door).
 *
 * supplier:db-sync --flag-obsolete demotes publish→pending any on-Woo product
 * with NO fresh-supplier offer, and products:flag-missing-buy-price demotes any
 * publish product whose local buy_price is null/≤0. Both are CORRECT per the
 * operator's business rule ("a product with no current supplier listing should
 * move to pending") — but they are a ONE-WAY DOOR: nothing promotes a product
 * back when a supplier lists it again, so the catalogue slowly under-sells.
 * This command is the missing INVERSE: it restores products that DO have a
 * current in-stock supplier offer back to `publish`.
 *
 * 260721-apr correction — 260713-rsp was built on the assumption that demoted
 * products were still `publish` on Woo (the demotion being LOCAL-only). That
 * assumption is WRONG: Woo MIRRORS the local status (Woo admin Pending 1,568 ≈
 * local pending 1,561), so a local-only restore leaves the product hidden on
 * the storefront. `--push-to-woo` (default OFF) pushes `status=publish` to Woo
 * so the restore is actually effective. See the --push-to-woo block below.
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
 * --push-to-woo (260721-apr, default OFF — WITHOUT it this command stays
 * byte-identically LOCAL-ONLY and makes ZERO Woo calls, and the feature test's
 * throwing WooClient stub guards that):
 *   - Only fires on --live (dry-run never touches Woo).
 *   - Pushes `{"status":"publish"}` to `products/{woo_product_id}` VIA WooClient
 *     — never a raw HTTP call — so it inherits the 260719-wth throttle
 *     (serialisation lock + per-minute ceiling + min-interval pacing), the
 *     shadow gate (WOO_WRITE_ENABLED=false ⇒ records a SyncDiff, no live
 *     write), the AbortGuard and the integration_events audit trail.
 *   - Products without a usable woo_product_id are skipped and counted.
 *   - A FAILED push rolls the local restore back to `pending` so the row stays
 *     in tomorrow's candidate cohort and the promote is retried. Leaving it
 *     `publish` locally would diverge from Woo permanently and silently (a
 *     `publish` row is never a restore candidate again).
 *
 * --dry-run is the DEFAULT (report + change nothing); --live applies. Idempotent
 * (only `status=pending` rows are candidates).
 *
 *   php artisan products:restore-sourceable-pending                             (dry-run — report the cohort)
 *   php artisan products:restore-sourceable-pending --live                      (restore LOCALLY only)
 *   php artisan products:restore-sourceable-pending --live --push-to-woo        (restore + push status to Woo)
 *   php artisan products:restore-sourceable-pending --include-listed-out-of-stock  (dry-run, broader cohort)
 *   php artisan products:restore-sourceable-pending --include-listed-out-of-stock --live
 *
 * Not `final` so a future Pest feature test can swap collaborators through the
 * container without subclassing (mirrors HydrateLiveStockCommand).
 */
class RestoreSourceablePendingCommand extends BaseCommand
{
    /** Local + Woo status a restored product is promoted to. */
    private const PUBLISHED_STATUS = 'publish';

    /** Status a demoted (or rolled-back) product carries. */
    private const DEMOTED_STATUS = 'pending';

    protected $signature = 'products:restore-sourceable-pending
        {--live : Apply the restore (DEFAULT is dry-run — report only)}
        {--include-listed-out-of-stock : Also restore products merely LISTED by a fresh supplier (any stock), not only current in-stock offers}
        {--push-to-woo : After the LOCAL restore, push status=publish to the product\'s Woo product via WooClient (throttled + still gated by WOO_WRITE_ENABLED → shadow SyncDiff when false). Off by default; requires --live (260721-apr).}
        {--limit=0 : Cap the number of candidates scanned (0 = unbounded)}';

    protected $description = 'Restore demoted pending + on-Woo products that have a CURRENT supplier offer back to publish. Local-only by default; --push-to-woo also pushes the status to Woo. Dry-run by default; --live applies (260713-rsp, 260721-apr).';

    public function __construct(
        private readonly LiveSupplierStockResolver $stock,
        private readonly WooClient $woo,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $includeListed = (bool) $this->option('include-listed-out-of-stock');
        $pushToWoo = (bool) $this->option('push-to-woo');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = ! $live;

        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'products:restore-sourceable-pending — '
            .($includeListed ? 'signal=fresh-listed (any stock)' : 'signal=in-stock (strict)')
            .($pushToWoo ? ' push-to-woo=ON' : ' push-to-woo=off (LOCAL only)')
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
        // 260721-apr --push-to-woo counters (all stay 0 when the flag is off).
        $pushedLive = 0;
        $shadowed = 0;
        $skippedNoWooId = 0;
        $pushFailed = 0;
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
                // LOCAL status realignment. update() (not a model save) mirrors
                // SupplierDbSyncCommand's demotion write so the inverse is
                // symmetric and fires no incidental side effects.
                Product::where('id', $product->id)->update(['status' => self::PUBLISHED_STATUS]);

                // 260721-apr — Woo mirrors the local status, so the LOCAL flip
                // alone leaves the product hidden on the storefront. Push it
                // (WooClient only: throttle + shadow gate + AbortGuard + audit).
                if ($pushToWoo) {
                    $wooId = (int) ($product->woo_product_id ?? 0);

                    if ($wooId <= 0) {
                        // Never been to Woo (or a placeholder id) — nothing to
                        // push. The local restore still stands.
                        $skippedNoWooId++;
                    } else {
                        try {
                            $result = $this->woo->put(
                                "products/{$wooId}",
                                ['status' => self::PUBLISHED_STATUS],
                            );

                            if (($result['shadow_mode'] ?? false) === true) {
                                $shadowed++;
                            } else {
                                $pushedLive++;
                            }
                        } catch (\Throwable $e) {
                            // Roll the local restore back so the row stays a
                            // candidate and tomorrow's run retries. Leaving it
                            // `publish` locally would diverge from Woo forever
                            // (publish rows are never restore candidates).
                            Product::where('id', $product->id)
                                ->update(['status' => self::DEMOTED_STATUS]);

                            $pushFailed++;
                            $this->warn(sprintf(
                                '  ✗ products/%d (sku=%s): %s — local restore rolled back to pending.',
                                $wooId,
                                $sku,
                                $e->getMessage(),
                            ));
                            Log::warning('restore_sourceable_pending.woo_push_failed', [
                                'sku' => $sku,
                                'woo_product_id' => $wooId,
                                'error' => $e->getMessage(),
                            ]);

                            continue;
                        }
                    }
                }
            }
            $restored++;
        }

        if ($samples !== []) {
            $this->newLine();
            $this->table(['sku', 'woo_product_id', 'signal'], $samples);
        }

        $rows = [
            ['scanned (pending + on-Woo)', $scanned],
            ['restored'.($dryRun ? ' (would)' : '').' → publish (local)', $restored],
            ['skipped: carve-out (custom-ms / excluded)', $skippedCarveOut],
            ['skipped: no supplier offer'.($includeListed ? '' : ' / out-of-stock'), $skippedNotSourceable],
            ['skipped: blank sku', $skippedNoSku],
        ];

        if ($pushToWoo) {
            $rows[] = ['pushed to Woo (live write)', $pushedLive];
            $rows[] = ['shadowed (SyncDiff — WOO_WRITE_ENABLED=false)', $shadowed];
            $rows[] = ['skipped: no usable woo_product_id (push)', $skippedNoWooId];
            $rows[] = ['push failed (local restore rolled back)', $pushFailed];
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], $rows);

        if ($pushToWoo) {
            // Single-line, grep-friendly summary (mirrors products:push-status-to-woo).
            $this->info(sprintf(
                'Woo push — live_pushed=%d shadowed=%d skipped_no_woo_id=%d push_failed=%d',
                $pushedLive,
                $shadowed,
                $skippedNoWooId,
                $pushFailed,
            ));

            if ($shadowed > 0) {
                $this->line('Shadowed rows = WOO_WRITE_ENABLED=false (review the sync_diffs table). '
                    .'The promote only reaches the storefront once WOO_WRITE_ENABLED=true.');
            }
            if ($dryRun) {
                $this->line('--push-to-woo requires --live; this dry-run made ZERO Woo calls.');
            }
        }

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
