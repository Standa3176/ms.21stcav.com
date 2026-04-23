<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\ProductAutoCreate\Services\ImagePayloadBuilder;
use App\Domain\ProductAutoCreate\Services\ProductImageFetcher;
use App\Domain\ProductAutoCreate\Services\ProductImageProcessor;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 02 Task 3 — ProcessAutoCreateImageJob.
 *
 * Async follow-up to Plan 03's CreateWooProductJob. Orchestrates the full
 * supplier-image → Woo-draft-product attach flow:
 *
 *   1. FETCH — walk the supplier URL fallback chain via ProductImageFetcher.
 *   2. PROCESS — resize + WebP-encode + strip EXIF via ProductImageProcessor.
 *      On fetch-exhausted (no URLs yielded a valid image), fall through to
 *      the placeholder URL + set requires_manual_image_review=true on the
 *      Product.
 *   3. STORE — write the processed bytes to the public disk at
 *      `auto-create-images/{product-slug}-main.webp`. URL is the public-disk
 *      ->url() output so Woo can reach it.
 *   4. PUT — `/wc/v3/products/{wooId}` with ImagePayloadBuilder output. Woo
 *      downloads the URL into its own media library (see Plan 02 Task 1
 *      WooUrlPassthroughSmokeTest for the documented contract).
 *   5. PERSIST — store the final public image_url on Product + clear/set the
 *      requires_manual_image_review flag. forceFill + saveQuietly (to avoid
 *      observer loops per Phase 6 Plan 01 A3 finding — saveQuietly suppresses
 *      BOTH saving AND saved in Laravel 12).
 *
 * Queue routing: `sync-bulk` (Phase 1 FOUND-09) via $this->onQueue() in the
 * constructor — NEVER via `public string $queue` property (Phase 5 Plan 02
 * lesson — PHP 8.4 trait collision between Dispatchable + InteractsWithQueue).
 *
 * Retries: 3 attempts with backoff [30s, 5m, 30m]. On final exhaustion, the
 * failed() hook creates a kind='auto_create_failed' Suggestion row so Plan 04
 * can wire an admin "Replay" action via the existing SuggestionApplier seam.
 */
final class ProcessAutoCreateImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    /**
     * @param  array<int, string|null>  $supplierFallbackUrls
     */
    public function __construct(
        public readonly int $productId,
        public readonly ?string $supplierImageUrl = null,
        public readonly array $supplierFallbackUrls = [],
    ) {
        // Phase 5 Plan 02 D-07: PHP 8.4 rejects $queue trait-property redeclare.
        // Use onQueue() inside the constructor instead.
        $this->onQueue('sync-bulk');
    }

    public function handle(
        ProductImageFetcher $fetcher,
        ProductImageProcessor $processor,
        ImagePayloadBuilder $payloadBuilder,
        WooClient $woo,
        IntegrationLogger $logger,
    ): void {
        $product = Product::findOrFail($this->productId);

        $publicPath = "auto-create-images/{$product->slug}-main.webp";
        $publicUrl = (string) config('product_auto_create.placeholder_image_url');
        $requiresReview = true;

        // ── FETCH ────────────────────────────────────────────────────────
        $binaryPath = $fetcher->fetch(
            primaryUrl: $this->supplierImageUrl ?? '',
            fallbackUrls: $this->supplierFallbackUrls,
        );

        if ($binaryPath !== null) {
            try {
                // ── PROCESS ──
                $webpBytes = $processor->process($binaryPath);

                // ── STORE ── public disk so Woo can download the URL
                Storage::disk('public')->put($publicPath, $webpBytes);
                $publicUrl = (string) Storage::disk('public')->url($publicPath);
                $requiresReview = false;
            } catch (\Throwable $e) {
                // Decode failure OR storage failure — log + fall through to
                // the placeholder URL. The requires_manual_image_review flag
                // stays true so ops spot + re-upload a real image.
                $logger->log([
                    'channel' => 'woo-auto-create',
                    'operation' => 'image.process.failed',
                    'method' => 'PROCESS',
                    'endpoint' => $this->supplierImageUrl ?? 'null',
                    'request_body' => ['product_id' => $product->id],
                    'response_body' => [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                    'http_status' => 0,
                    'latency_ms' => 0,
                    'status' => 'failed',
                    'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
                ]);
            } finally {
                @unlink($binaryPath);
            }
        } else {
            // Fetcher returned null — every URL in the fallback chain fell
            // through. Placeholder + manual-review flag.
            $logger->log([
                'channel' => 'woo-auto-create',
                'operation' => 'image.fetch_exhausted',
                'method' => 'FETCH',
                'endpoint' => $this->supplierImageUrl ?? 'null',
                'request_body' => [
                    'product_id' => $product->id,
                    'fallbacks' => count($this->supplierFallbackUrls),
                ],
                'response_body' => ['action' => 'using_placeholder'],
                'http_status' => 0,
                'latency_ms' => 0,
                'status' => 'success',
                'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
            ]);
        }

        // ── PUT to Woo (shadow-mode aware via WooClient::put) ────────────
        $payload = $payloadBuilder->build($product, $publicUrl);
        $woo->put("/products/{$product->woo_product_id}", $payload);

        // ── PERSIST — forceFill + saveQuietly avoids the Phase 2 activity_log
        //    bloat pattern AND the Plan 01 A3 observer-suppression finding.
        $product->forceFill([
            'image_url' => $publicUrl,
            'requires_manual_image_review' => $requiresReview,
        ])->saveQuietly();
    }

    /**
     * Last-retry failure hook. Writes a kind='auto_create_failed' Suggestion
     * row so admin can click Replay in the Filament review inbox (Plan 04
     * wires the actual action). Mirrors Phase 4 Plan 03 CrmPushRetryApplier
     * + Phase 5 Plan 02 DLQ precedent.
     */
    public function failed(\Throwable $e): void
    {
        Suggestion::create([
            'kind' => 'auto_create_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => Context::get('correlation_id'),
            'proposed_at' => now(),
            'evidence' => [
                'source' => 'ProcessAutoCreateImageJob',
                'product_id' => $this->productId,
                'supplier_image_url' => $this->supplierImageUrl,
                'fallback_count' => count($this->supplierFallbackUrls),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ],
        ]);
    }
}
