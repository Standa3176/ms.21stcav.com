<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Events\AutoCreateAttempted;
use App\Domain\ProductAutoCreate\Events\AutoCreateFailed;
use App\Domain\ProductAutoCreate\Events\AutoCreateSucceeded;
use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\ProductAutoCreate\Services\ProductContentBuilder;
use App\Domain\ProductAutoCreate\Services\ProductMatcher;
use App\Domain\ProductAutoCreate\Services\ProductSlugGenerator;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 Plan 03 — the core orchestrator for auto-create drafts (AUTO-01,
 * AUTO-02, AUTO-05).
 *
 * Queue: sync-woo-push (Woo REST write — respects shared 429 backoff).
 * Retries: 3 with [30s, 5m, 30m] backoff (Phase 4 Pitfall P4-B precedent).
 * On exhaustion: failed() hook writes a kind='auto_create_failed' Suggestion
 * so the Plan 04 admin replay action has a row to act on (mirrors Phase 4
 * CrmPushRetryApplier DLQ pattern).
 *
 * handle() pipeline:
 *   1. AutoCreateAttempted event (diagnostic anchor + listener fanout).
 *   2. ProductMatcher::existsNormalised → AutoCreateFailed('duplicate') + return.
 *   3. SupplierClient::fetchSingleProduct → AutoCreateFailed('supplier_not_found')
 *      when the supplier returns empty (T-06-03-01 tampering guard).
 *   4. ProductContentBuilder::compile → SEO-template {title, slug, meta, short, long}.
 *   5. ProductSlugGenerator::generate → client-side uniqueness candidate.
 *   6. Pre-POST Woo slug collision probe (Pitfall P6-G) → regenerate -{sku}
 *      when Woo already hosts a colliding slug.
 *   7. TaxonomyResolver::resolveBrand + resolveCategory. Missing EITHER → create
 *      Product with auto_create_status='needs_brand_or_category_assignment',
 *      short-circuit (no Woo POST, no image job, no AutoCreateSucceeded).
 *   8. Product::create (auto_create_status='draft', status='draft').
 *   9. RuleResolver + PriceCalculator → sell_price_pennies. SupplierPriceUnusableException
 *      downgrades to sell_price=null (ops triages); any other throw propagates + retries fire.
 *  10. WooClient::post('/products') — images payload empty (Plan 02 job appends).
 *  11. forceFill + saveQuietly with Woo-returned slug + woo_product_id + sell_price
 *      (Pitfall P6-G Woo-wins-on-create reconciliation).
 *  12. ProcessAutoCreateImageJob::dispatch (Plan 02 image pipeline — sync-bulk queue).
 *  13. CompletenessScorer::score → write 3 completeness columns via forceFill.
 *  14. AutoCreateSucceeded event.
 */
final class CreateWooProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(
        public readonly string $sku,
        public readonly ?string $suggestionId = null,
    ) {
        // PHP 8.4 trait-collision guard — NEVER public string $queue (Phase 5 Plan 02 lesson).
        $this->onQueue('sync-woo-push');
    }

    public function handle(
        WooClient $woo,
        SupplierClient $supplier,
        ProductContentBuilder $content,
        ProductSlugGenerator $slugGenerator,
        ProductMatcher $matcher,
        TaxonomyResolver $taxonomy,
        RuleResolver $ruleResolver,
        PriceCalculator $calculator,
        CompletenessScorer $scorer,
    ): void {
        event(new AutoCreateAttempted($this->sku));

        // ── Duplicate gate (AUTO-08) ────────────────────────────────────────
        if ($matcher->existsNormalised($this->sku)) {
            event(new AutoCreateFailed($this->sku, reason: 'duplicate'));

            return;
        }

        // ── Supplier lookup (T-06-03-01 tampering guard) ────────────────────
        $supplierData = $supplier->fetchSingleProduct($this->sku);
        if ($supplierData === []) {
            event(new AutoCreateFailed($this->sku, reason: 'supplier_not_found'));

            return;
        }

        // ── Content compile (AUTO-02) ───────────────────────────────────────
        $compiled = $content->compile($supplierData);

        // ── Client-side slug uniqueness (D-05) ──────────────────────────────
        $uniqueSlug = $slugGenerator->generate($compiled['title'], $this->sku);

        // ── Pre-POST Woo slug collision probe (Pitfall P6-G) ────────────────
        $uniqueSlug = $this->ensureSlugFreeOnWoo($woo, $uniqueSlug);

        // ── Taxonomy resolution (AUTO-02) ───────────────────────────────────
        $brandId = $taxonomy->resolveBrand((string) ($supplierData['brand'] ?? ''));
        $categoryId = $taxonomy->resolveCategory((string) ($supplierData['category'] ?? ''));

        $buyPennies = (int) round(((float) ($supplierData['price'] ?? 0)) * 100);

        // ── Create local Product row ───────────────────────────────────────
        $autoCreateStatus = ($brandId === null || $categoryId === null)
            ? 'needs_brand_or_category_assignment'
            : 'draft';

        $product = Product::create([
            'sku' => $this->sku,
            'name' => $compiled['title'],
            'slug' => $uniqueSlug,
            'short_description' => $compiled['short_description'],
            'long_description' => $compiled['long_description'],
            'meta_description' => $compiled['meta_description'],
            'buy_price' => $buyPennies / 100,
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'auto_create_status' => $autoCreateStatus,
            'status' => 'draft',
            'type' => 'simple',
        ]);

        // ── Needs-assignment short-circuit — no Woo POST, no image, no success ─
        if ($autoCreateStatus === 'needs_brand_or_category_assignment') {
            Log::info('CreateWooProductJob: taxonomy unresolved; parked for manual triage', [
                'sku' => $this->sku,
                'product_id' => $product->id,
                'supplier_brand' => $supplierData['brand'] ?? null,
                'supplier_category' => $supplierData['category'] ?? null,
                'correlation_id' => Context::get('correlation_id'),
            ]);
            $this->recomputeCompleteness($product, $scorer);

            return;
        }

        // ── Pricing (Phase 3 engine) ───────────────────────────────────────
        $sellPennies = $this->computeSellPennies(
            $product,
            $buyPennies,
            $ruleResolver,
            $calculator,
        );

        // ── Build Woo payload ──────────────────────────────────────────────
        $payload = [
            'name' => $compiled['title'],
            'slug' => $uniqueSlug,
            'status' => 'draft',  // AUTO-07 draft-first lock
            'type' => 'simple',
            'sku' => $this->sku,
            'regular_price' => $sellPennies > 0
                ? (string) number_format($sellPennies / 100, 2, '.', '')
                : '0.00',
            'short_description' => $compiled['short_description'],
            'description' => $compiled['long_description'],
            'meta_data' => [
                ['key' => '_yoast_wpseo_metadesc', 'value' => $compiled['meta_description']],
            ],
            'categories' => [['id' => $categoryId]],
            'images' => [],
        ];

        $response = $woo->post('/products', $payload);

        // ── Reconcile Woo-returned slug + id (Pitfall P6-G) ────────────────
        $wooId = (int) ($response['id'] ?? 0);
        $finalSlug = (string) ($response['slug'] ?? $uniqueSlug);

        $product->forceFill([
            'woo_product_id' => $wooId > 0 ? $wooId : null,
            'slug' => $finalSlug,
            'sell_price' => $sellPennies > 0 ? $sellPennies / 100 : null,
        ])->saveQuietly();

        // ── Image follow-up (Plan 02) — sync-bulk queue ─────────────────────
        $fallbacks = is_array($supplierData['image_fallback_urls'] ?? null)
            ? array_values((array) $supplierData['image_fallback_urls'])
            : [];

        ProcessAutoCreateImageJob::dispatch(
            $product->id,
            $supplierData['image_url'] ?? null,
            $fallbacks,
        );

        // ── Completeness snapshot (listener recomputes on supplier feed mutations) ─
        $this->recomputeCompleteness($product->fresh(), $scorer);

        $fresh = $product->fresh();
        event(new AutoCreateSucceeded(
            productId: (int) $fresh->id,
            wooProductId: $wooId,
            sku: $this->sku,
            slug: $finalSlug,
            completenessScore: (int) ($fresh->completeness_score ?? 0),
            autoCreateStatus: (string) $fresh->auto_create_status,
        ));
    }

    /**
     * Terminal-failure DLQ hook (Phase 1 D-17 / Phase 4 Plan 03 precedent).
     * Exhausted retries land in Suggestions so an admin can Replay via Plan 04.
     */
    public function failed(\Throwable $e): void
    {
        Suggestion::create([
            'kind' => 'auto_create_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => Context::get('correlation_id'),
            'proposed_at' => now(),
            'evidence' => [
                'source' => 'CreateWooProductJob',
                'sku' => $this->sku,
                'original_suggestion_id' => $this->suggestionId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ],
        ]);
    }

    /**
     * Pitfall P6-G mitigation: before POST, ask Woo whether the candidate slug
     * is free. On collision, append -{sku-lowercased} for deterministic
     * disambiguation. A second collision is rare enough that we accept Woo's
     * server-side -{n} suffix + the post-POST reconciliation that re-reads
     * $response['slug'] back onto Product.slug.
     */
    private function ensureSlugFreeOnWoo(WooClient $woo, string $candidate): string
    {
        try {
            $existing = $woo->get('/products', ['slug' => $candidate, 'per_page' => 1]);
        } catch (\Throwable) {
            // Network / 4xx — accept the candidate and let Woo's own unique-slug
            // logic + the post-POST reconcile fix any divergence.
            return $candidate;
        }

        if (empty($existing)) {
            return $candidate;
        }

        return $candidate.'-'.strtolower(trim($this->sku));
    }

    /**
     * Phase 3 pricing: resolve rule → compute pennies. SupplierPriceUnusableException
     * (zero/negative supplier price) downgrades to 0 so the draft ships with
     * regular_price='0.00' for ops to fill in. Any other failure propagates so
     * the retry chain fires and eventually DLQs via failed().
     */
    private function computeSellPennies(
        Product $product,
        int $buyPennies,
        RuleResolver $ruleResolver,
        PriceCalculator $calculator,
    ): int {
        if ($buyPennies <= 0) {
            return 0;
        }

        try {
            $resolution = $ruleResolver->resolve($product);

            return $calculator->compute($buyPennies, $resolution->marginBasisPoints);
        } catch (SupplierPriceUnusableException) {
            return 0;
        }
    }

    private function recomputeCompleteness(Product $product, CompletenessScorer $scorer): void
    {
        $score = $scorer->score($product);
        $product->forceFill([
            'completeness_score' => $score['score'],
            'completeness_missing_fields' => $score['missing_fields'],
            'completeness_computed_at' => now(),
        ])->saveQuietly();
    }
}
