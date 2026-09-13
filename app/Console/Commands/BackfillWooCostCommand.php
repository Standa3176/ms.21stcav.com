<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooFieldComparator;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WooProductWriter;

/**
 * Quick task 260913-pyz — products:backfill-woo-cost.
 *
 * One-time (re-runnable) backfill of `_alg_wc_cog_cost` onto EXISTING products
 * whose Woo record has no cost-of-goods meta at all.
 *
 * WHY THESE PRODUCTS EXIST
 *
 * Woo's cost field was populated in bulk at the 2026-05-23 migration and never
 * maintained. Three things combined to make the gap permanent and invisible:
 *
 *   1. CreateWooProductJob did not write the key (fixed in this same task), so
 *      every app-created product reached Woo with no cost.
 *   2. WooFieldComparator SILENT-SKIPS buy_price when Woo lacks the key — a
 *      deliberate defensive contract, because most Woo installs have no WC COG
 *      plugin and emitting a diff per absence would flood sync_diffs. This
 *      install DOES have the plugin, so the contract suppresses a real
 *      divergence class here.
 *   3. cutover:auto-sync (23:00 nightly) only pushes what the comparator
 *      flags. Nothing flagged, nothing pushed.
 *
 * Born blank meant blank forever. Measured 2026-09-13: 6 of 25 sampled
 * published products (24%) had no Woo cost, every one created after the
 * 2026-05-23 load.
 *
 * WHY IT MATTERS (the app itself does not care — it uses products.buy_price)
 *
 *   - WooCommerce profit reporting is wrong for those products.
 *   - A B2BKing dynamic rule computing "Cost + x%" prices them at x% of ZERO.
 *     Group 167509 "Trade" carries exactly such a rule; it is unarmed only
 *     because that group has no users.
 *
 * WHY IT DOES NOT LOOP
 *
 * woo:import-products may only SEED buy_price from Woo COG — never overwrite a
 * non-null local cost (quick task 260809-uza broke that circular authority on
 * SKU 9C941AA). So pushing local cost out cannot come back and cement itself.
 *
 * WRITE SAFETY
 *
 * Delegates to WooProductWriter::putProductFields($product, ['buy_price']),
 * which pre-GETs and merges. A blind PUT with
 * meta_data=[{key:_alg_wc_cog_cost}] WIPES every other meta entry — b2bking
 * group prices included. That contract is non-negotiable and is the reason
 * this command does not build its own payload.
 *
 * Skips products whose cost is unknown: writing 0.0000 reads as a real value
 * to WC COG and to any dynamic rule, which is worse than an absent key.
 *
 * NOT scheduled — one-time. cutover:auto-sync keeps cost fresh afterwards,
 * because once the key EXISTS the comparator compares it normally.
 *
 * Operator entry points:
 *   php artisan products:backfill-woo-cost                 (DRY-RUN, default)
 *   php artisan products:backfill-woo-cost --limit=50 --apply
 *   php artisan products:backfill-woo-cost --apply         (LIVE, all)
 */
final class BackfillWooCostCommand extends BaseCommand
{
    /** Abort after this many CONSECUTIVE failures — an environmental fault. */
    private const MAX_CONSECUTIVE_FAILURES = 15;

    protected $signature = 'products:backfill-woo-cost
        {--skus= : Comma-separated SKUs instead of the default published set}
        {--limit=0 : Max products to inspect this run (0 = all)}
        {--apply : Write to Woo. WITHOUT THIS NOTHING IS WRITTEN.}';

    protected $description = 'Backfill _alg_wc_cog_cost onto Woo products that have no cost meta. Dry-run by default.';

    public function __construct(
        private readonly WooClient $woo,
        private readonly WooProductWriter $writer,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $query = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id')
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0);

        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->option('skus') ?? ''))
        )));
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        $this->info(sprintf(
            '%s — inspecting %d published product(s) with a known cost.',
            $apply ? 'APPLY' : 'DRY-RUN',
            $products->count(),
        ));

        $missing = 0;
        $present = 0;
        $written = 0;
        $failed = 0;
        $unreachable = 0;
        $consecutiveFailures = 0;

        foreach ($products as $product) {
            try {
                $remote = $this->woo->get('products/'.((int) $product->woo_product_id));
            } catch (\Throwable $e) {
                $unreachable++;
                $consecutiveFailures++;
                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->error(sprintf(
                        'Aborted: %d consecutive read failures. That is environmental, not per-product.',
                        $consecutiveFailures,
                    ));

                    break;
                }

                continue;
            }
            $consecutiveFailures = 0;

            if ($this->hasCostMeta($remote)) {
                $present++;

                continue;
            }

            $missing++;
            $this->line(sprintf(
                '  %-26s cost %-12s → would write %s',
                (string) $product->sku,
                (string) $product->buy_price,
                number_format((float) $product->buy_price, 4, '.', ''),
            ));

            if (! $apply) {
                continue;
            }

            $result = $this->writer->putProductFields($product, ['buy_price']);
            if (($result['status'] ?? '') === 'ok') {
                $written++;
            } else {
                $failed++;
                $this->warn(sprintf('    %s — %s', (string) $product->sku, (string) ($result['status'] ?? 'unknown')));
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%d already had cost, %d missing it, %d written, %d failed, %d unreachable.',
            $present,
            $missing,
            $written,
            $failed,
            $unreachable,
        ));

        if (! $apply && $missing > 0) {
            $this->comment('Nothing was written. Re-run with --apply to backfill these.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $remote */
    private function hasCostMeta(array $remote): bool
    {
        foreach (($remote['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            if ((string) ($meta['key'] ?? '') !== WooFieldComparator::BUY_PRICE_META_KEY) {
                continue;
            }

            // An empty string is as absent as a missing key, for WC COG.
            return trim((string) ($meta['value'] ?? '')) !== '';
        }

        return false;
    }
}
