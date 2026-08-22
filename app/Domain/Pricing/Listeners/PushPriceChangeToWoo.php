<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Listeners;

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Services\WooRegularPriceFormatter;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Concerns\HandlesWooWriteThrottle;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Support\WooWriteMetrics;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Core-loop step #2 — push a recomputed sell price to WooCommerce.
 *
 * Subscribes to ProductPriceChanged (emitted by PriceRecomputer AND
 * pricing:undercut-competitors) and PUTs the new price to Woo's regular_price.
 * This is the listener the EventServiceProvider comment has long referenced as
 * "the downstream Woo PUT on ProductPriceChanged" — it finally exists.
 *
 * Gating: the actual write goes through WooClient::put → writeOrShadow, so with
 * WOO_WRITE_ENABLED=false it records a SyncDiff (shadow) instead of hitting Woo.
 * Nothing reaches the live store until the cutover flips that flag — running the
 * pricing command now just stages SyncDiffs you can review.
 *
 * Queue: sync-woo-push (FOUND-09; sync-woo-push-supervisor caps at ≤3 processes
 * for Woo's ~100 req/min headroom).
 *
 * VAT: sell_price is VAT-INCLUSIVE. By default we push inc-VAT to regular_price
 * (matches CreateWooProductJob). If the Woo store enters prices ex-VAT, set
 * WOO_PUSH_PRICES_EX_VAT=true and we strip VAT first. CONFIRM this against the
 * storefront before cutover — a wrong basis is a 20% price error.
 *
 * Skips silently (logs) when the product has no woo_product_id yet (e.g. a local
 * draft not yet created on Woo) — there is nothing to update.
 */
final class PushPriceChangeToWoo implements ShouldQueue
{
    use HandlesWooWriteThrottle;
    use InteractsWithQueue;

    public int $tries = 3;

    /**
     * 260822-rmo — genuine-failure budget.
     *
     * With retryUntil() set (from HandlesWooWriteThrottle) the queue SKIPS the
     * attempts check entirely, so `tries` no longer terminates anything while
     * the deferral window is open. maxExceptions is what still stops a
     * genuinely broken push: 3 UNCAUGHT exceptions and the job fails, on the
     * same backoff schedule as before. A throttle is caught and released, so
     * it never touches this budget.
     */
    public int $maxExceptions = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly WooClient $woo,
        private readonly WooRegularPriceFormatter $priceFormatter,
    ) {}

    public function viaQueue(): string
    {
        // 260719-wth — dedicated single-worker write queue. This was the incident's
        // main offender (222 concurrent price pushes); keeping it off the shared
        // sync-woo-push pool stops a price-push backlog starving other queues.
        return 'woo-writes';
    }

    public function handle(ProductPriceChanged $event): void
    {
        $product = Product::query()->where('id', $event->productId)->first();
        if ($product === null || $product->woo_product_id === null) {
            Log::info('pricing.woo_push_skipped_no_woo_id', [
                'product_id' => $event->productId,
                'sku' => $event->sku,
                'new_pennies' => $event->newPennies,
            ]);

            return;
        }

        // Quick task 260701-n4y — skip products not on the storefront. Drafts /
        // pending (old `manual` imports) are the sole source of the 204 stale
        // woo_product_ids Woo 400s on with woocommerce_rest_product_invalid_id;
        // they aren't published so pushing a price is pointless noise. Live-price
        // sync for PUBLISHED products is unaffected — this only silences drafts.
        if ($product->status !== 'publish') {
            Log::info('pricing.woo_push_skipped_not_published', [
                'product_id' => $product->id,
                'sku' => $event->sku,
                'status' => $product->status,
            ]);

            return;
        }

        // sell_price (event newPennies) is VAT-inclusive. 260822-rmo — the
        // inc/ex-VAT decision now lives in ONE place shared with the nightly
        // sell_price reconciler, so the event-driven push and the backstop can
        // never disagree about the basis (a divergence there is a silent 20%
        // error on every reconciled product).
        $regularPrice = $this->priceFormatter->fromPennies($event->newPennies);

        // No leading slash — the Woo SDK 404s ("rest_no_route") on a leading "/".
        if ($event->variantId !== null) {
            $variant = ProductVariant::query()->where('id', $event->variantId)->first();
            if ($variant === null || $variant->woo_variation_id === null) {
                Log::info('pricing.woo_push_skipped_no_woo_variation', [
                    'variant_id' => $event->variantId,
                    'sku' => $event->sku,
                ]);

                return;
            }
            $this->putOrClearStale(
                "products/{$product->woo_product_id}/variations/{$variant->woo_variation_id}",
                $regularPrice,
                $product,
                $event,
            );

            return;
        }

        $this->putOrClearStale(
            "products/{$product->woo_product_id}",
            $regularPrice,
            $product,
            $event,
        );
    }

    /**
     * Quick task 260701-n4y — PUT a price to Woo, but self-heal a stale
     * woo_product_id instead of failing the job.
     *
     * On a Woo error whose message contains the WC error CODE
     * `woocommerce_rest_product_invalid_id` (observed message shape:
     * "... Error: Invalid ID. [woocommerce_rest_product_invalid_id] ...") the
     * product's Woo record was deleted underneath us: we NULL the local
     * woo_product_id (saveQuietly — no observer/audit churn), log it, and RETURN
     * WITHOUT rethrowing so the job succeeds, stops retrying, and the product is
     * flagged (null woo id) for re-link. Any OTHER exception rethrows so
     * genuine/transient errors (5xx, 429-exhaustion, auth) still retry.
     *
     * Nulling woo_product_id is the correct recovery even on the variant path —
     * an invalid parent product id makes the variation write moot.
     */
    private function putOrClearStale(string $path, string $regularPrice, Product $product, ProductPriceChanged $event): void
    {
        try {
            $this->woo->put($path, ['regular_price' => $regularPrice]);
        } catch (WooWriteThrottleException $e) {
            // 260822-rmo — back-pressure, not failure. DEFER (release) so the
            // write is re-attempted when the ceiling reopens. Catching it here
            // means no exception escapes, so maxExceptions stays untouched and
            // retryUntil keeps the job alive across the whole burst.
            //
            // This is the exact path that lost 5,319 price pushes between
            // 2026-08-18 and 2026-08-22: the throw used to burn all 3 attempts
            // inside the burst window (T+0, T+30, T+150) and the job died.
            $this->releaseForWooThrottle($e, [
                'product_id' => $product->id,
                'sku' => $event->sku,
                'path' => $path,
                'regular_price' => $regularPrice,
            ]);

            return;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'woocommerce_rest_product_invalid_id')) {
                Log::warning('pricing.woo_push_stale_id_cleared', [
                    'product_id' => $product->id,
                    'sku' => $event->sku,
                    'woo_product_id' => $product->woo_product_id,
                    'path' => $path,
                ]);
                $product->forceFill(['woo_product_id' => null])->saveQuietly();

                return; // stale link cleared + flagged; do NOT rethrow (no retry, no failed_jobs)
            }

            throw $e; // genuine/transient — let the job retry
        }
    }

    /**
     * 260822-rmo — terminal-failure visibility.
     *
     * Before this, an exhausted price push left NOTHING behind but a
     * failed_jobs row: no log line naming the SKU, no audit entry, no counter.
     * 5,319 prices went missing between 2026-08-18 and 2026-08-22 and nothing
     * in the app noticed.
     *
     * Deliberately NOT a replay queue. Replaying a stale price payload is the
     * wrong recovery — by the time anyone looks, sell_price may have moved
     * again. The correct re-drive is reconciliation against the CURRENT local
     * price: `cutover:auto-sync --field=sell_price`. What this hook owes the
     * operator is enough structured detail to diagnose the failure and to
     * confirm the reconciler later closed it.
     *
     * A throttle NEVER reaches here — it is caught and released in
     * putOrClearStale(), so this handler only ever sees genuine errors.
     */
    public function failed(ProductPriceChanged $event, \Throwable $e): void
    {
        WooWriteMetrics::increment(WooWriteMetrics::FAILED);

        $product = Product::query()->where('id', $event->productId)->first();

        $context = [
            'product_id' => $event->productId,
            'woo_product_id' => $product?->woo_product_id,
            'variant_id' => $event->variantId,
            'sku' => $event->sku,
            'intended_sell_pennies' => $event->newPennies,
            'intended_regular_price' => number_format($event->newPennies / 100, 2, '.', ''),
            'push_prices_ex_vat' => (bool) config('services.woo.push_prices_ex_vat', false),
            // Null on the queued-LISTENER path: CallQueuedListener::failed()
            // resolves a FRESH handler from the container (Events/
            // CallQueuedListener.php:209-219), so no job is bound. Kept
            // null-safe rather than omitted — correlation_id (added by
            // Auditor) is the identifier that actually ties this back to the
            // Horizon/failed_jobs row.
            'job_uuid' => $this->job?->uuid(),
            'attempts' => $this->job?->attempts(),
            'queue' => $this->job?->getQueue(),
            'error_class' => $e::class,
            'error' => $e->getMessage(),
            'failed_at' => now()->toIso8601String(),
        ];

        Log::error('pricing.woo_push_failed', $context);

        try {
            app(Auditor::class)->record('pricing.woo_push_failed', $context);
        } catch (\Throwable $auditError) {
            // Never let the audit write mask the original failure.
            Log::warning('pricing.woo_push_failed_audit_error', ['error' => $auditError->getMessage()]);
        }
    }
}
