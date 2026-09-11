<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Services\WooClient;
use App\Domain\TradePricing\Services\TradeCostAdjustmentResolver;
use App\Domain\TradePricing\Services\TradeStorefrontPricer;
use Illuminate\Support\Facades\Log;

/**
 * NOTE ON PLACEMENT (260910-lwv): these live in the Pricing domain, not
 * TradePricing, because TradePricing is deliberately a thin read-side layer
 * whose deptrac allow-list is [Foundation, Pricing, Products, Webhooks] — it
 * must never reach into Sync. Pricing may depend on BOTH Sync and
 * TradePricing, so a command that reads a trade price and writes it to Woo
 * belongs here. Widening TradePricing's allow-list would have been the easy
 * fix and the wrong one; DeptracTradePricingLayerTest guards that boundary.
 */

/**
 * Quick task 260910-lwv — compute and publish trade prices to B2BKing.
 *
 * DRY-RUN IS THE DEFAULT. `--live` writes. This mirrors
 * pricing:undercut-competitors, which has bitten people the other way round;
 * a command that reprices a storefront should never write because somebody
 * forgot a flag.
 *
 * Writes ONE meta key per product —
 * `b2bking_regular_product_price_group_{group}` — through the same WooClient
 * (and therefore the same 60/min throttle) as every other Woo write. Retail
 * `regular_price` is never touched: trade and retail are independent fields.
 *
 * A SUPPRESSED product has its meta cleared to an empty string rather than
 * left stale. B2BKing treats empty as "no group price" and falls back to
 * retail, which is the correct outcome — whereas a stale trade price from
 * before a cost rise would quietly keep selling at a loss.
 *
 * Usage:
 *   php artisan trade:sync                          # dry-run, whole catalogue
 *   php artisan trade:sync --skus=ABC,DEF           # dry-run, two products
 *   php artisan trade:sync --skus=ABC --live        # write those two
 *   php artisan trade:sync --limit=50 --live        # write a first batch
 */
final class TradeSyncCommand extends BaseCommand
{
    protected $signature = 'trade:sync
        {--skus= : Comma-separated SKU list. Default: all published products.}
        {--limit=0 : Cap how many products are processed (0 = no cap).}
        {--changed-only : Skip products whose Woo meta already holds the computed price.}
        {--live : Write to Woo. WITHOUT THIS NOTHING IS WRITTEN.}';

    protected $description = 'Compute trade prices and publish them to the B2BKing group price meta (dry-run by default).';

    /** How many throttle windows to wait out per product before giving up on it. */
    private const MAX_THROTTLE_WAITS = 10;

    /**
     * Abort the whole run after this many CONSECUTIVE write failures.
     *
     * 260911-t80 — the TLS certificate expired mid-afternoon and this command
     * ground through all 4,333 products retrying a failure that could never
     * succeed, reporting "0 written, 4333 failed" an hour later. When every
     * write is failing the cause is environmental, not per-product, and the
     * operator wants to know in seconds.
     */
    private const MAX_CONSECUTIVE_FAILURES = 15;

    public function __construct(
        private readonly TradeStorefrontPricer $pricer,
        private readonly TradeCostAdjustmentResolver $adjustments,
        private readonly WooClient $woo,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $limit = (int) $this->option('limit');
        $changedOnly = (bool) $this->option('changed-only');
        $groupId = (int) config('b2b.storefront.group_id');
        $metaKey = sprintf((string) config('b2b.storefront.meta_key_template'), $groupId);

        if ($groupId <= 0) {
            $this->error('b2b.storefront.group_id is not configured — refusing to guess a B2BKing group.');

            return self::FAILURE;
        }

        $this->adjustments->flush();

        $query = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id');

        $skus = $this->parseSkus();
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        $this->info(sprintf(
            '%s — trade prices for %d product(s) → group %d (%s), margins %.2f%%–%.2f%%.',
            $live ? 'LIVE' : 'DRY-RUN',
            $products->count(),
            $groupId,
            $metaKey,
            ((int) config('b2b.storefront.min_margin_bps')) / 100,
            ((int) config('b2b.storefront.max_margin_bps')) / 100,
        ));

        $counts = ['target' => 0, 'retail_capped' => 0, 'suppressed' => 0, 'skipped_unchanged' => 0, 'failed' => 0];
        $written = 0;
        $consecutiveFailures = 0;
        $aborted = false;

        foreach ($products as $product) {
            $result = $this->pricer->price($product);
            $value = $result->isPublishable()
                ? number_format($result->pennies / 100, 2, '.', '')
                : '';

            if ($changedOnly && $this->wooAlreadyHolds($product, $metaKey, $value)) {
                $counts['skipped_unchanged']++;

                continue;
            }

            $counts[$result->source]++;

            $this->line(sprintf(
                '  %-24s %-14s %8s  (retail %8s, adj %.1f%%)%s',
                (string) $product->sku,
                $result->source,
                $value === '' ? '—' : $value,
                number_format((float) ($product->sell_price ?? 0), 2),
                $result->costAdjustment * 100,
                $result->reason !== null ? '  '.$result->reason : '',
            ));

            if (! $live) {
                continue;
            }

            if ($this->writeWithThrottleWait($product, $metaKey, $value)) {
                $written++;
                $consecutiveFailures = 0;
            } else {
                $counts['failed']++;
                $consecutiveFailures++;

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->error(sprintf(
                        'ABORTED — %d consecutive write failures. This is environmental (expired TLS, '
                        .'credentials, Woo down), not per-product. Nothing further was attempted.',
                        $consecutiveFailures,
                    ));
                    $aborted = true;

                    break;
                }
            }
        }

        $this->info(sprintf(
            '%s complete — %d at target, %d capped by retail, %d suppressed, %d unchanged, %d failed. %d written to Woo.',
            $live ? 'LIVE' : 'DRY-RUN',
            $counts['target'],
            $counts['retail_capped'],
            $counts['suppressed'],
            $counts['skipped_unchanged'],
            $counts['failed'],
            $written,
        ));

        if (! $live) {
            $this->comment('Nothing was written. Re-run with --live to publish these prices.');
        }

        return ($aborted || $counts['failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Write one product's trade price, WAITING OUT the Woo throttle.
     *
     * 260911 — the first full live run dropped most of the catalogue. The
     * throttle (60 live writes/min) raises WooWriteThrottleException, which is
     * documented as RETRYABLE: "the correct response is to requeue the job so
     * the write is attempted again later". The original code caught it as a
     * generic Throwable, counted it failed and moved to the next product — so
     * roughly 60 products landed per minute and every other one in that window
     * was silently skipped.
     *
     * This is the same shape as the August incident (260822-rmo) where a
     * thrown throttle consumed queue attempts and killed 5,319 price pushes.
     * A queued caller releases; this command is synchronous, so it sleeps for
     * the interval the exception itself reports and re-attempts the SAME
     * product. Bounded, so a stuck lock ends the product rather than the run.
     */
    private function writeWithThrottleWait(Product $product, string $metaKey, string $value): bool
    {
        $path = 'products/'.((int) $product->woo_product_id);
        $payload = ['meta_data' => [['key' => $metaKey, 'value' => $value]]];
        $waits = 0;

        while (true) {
            try {
                $this->woo->put($path, $payload);

                return true;
            } catch (WooWriteThrottleException $e) {
                $waits++;
                if ($waits > self::MAX_THROTTLE_WAITS) {
                    Log::warning('trade.sync.throttle_exhausted', [
                        'sku' => $product->sku,
                        'waits' => $waits,
                    ]);
                    $this->warn(sprintf('    %s — throttle did not clear after %d waits, skipping', (string) $product->sku, $waits - 1));

                    return false;
                }

                $seconds = $e->retryAfterSeconds();
                $this->line(sprintf('    throttled — waiting %ds', $seconds));
                sleep($seconds);
            } catch (\Throwable $e) {
                Log::warning('trade.sync.write_failed', [
                    'sku' => $product->sku,
                    'woo_product_id' => $product->woo_product_id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $this->warn(sprintf('    write failed for %s — %s', (string) $product->sku, $e->getMessage()));

                return false;
            }
        }
    }

    /**
     * True when Woo already carries exactly this value — lets a re-run skip
     * products that have not moved, at the cost of one GET each. Only worth
     * it on a catalogue-wide sweep where writes are the scarce resource.
     */
    private function wooAlreadyHolds(Product $product, string $metaKey, string $value): bool
    {
        try {
            $remote = $this->woo->get('products/'.((int) $product->woo_product_id));
        } catch (\Throwable) {
            return false;
        }

        foreach (($remote['meta_data'] ?? []) as $meta) {
            if (($meta['key'] ?? null) === $metaKey) {
                return (string) ($meta['value'] ?? '') === $value;
            }
        }

        return $value === '';
    }

    /** @return array<int, string> */
    private function parseSkus(): array
    {
        $raw = trim((string) ($this->option('skus') ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }
}
