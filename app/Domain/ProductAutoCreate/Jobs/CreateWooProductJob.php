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
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Concerns\HandlesWooWriteThrottle;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
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
    use HandlesWooWriteThrottle;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * 260822-rmo — genuine-failure budget (see HandlesWooWriteThrottle).
     * retryUntil() suspends the `tries` check, so this is what still fails a
     * genuinely broken create; a throttle is deferred and never counted.
     */
    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(
        public readonly string $sku,
        public readonly ?string $suggestionId = null,
    ) {
        // PHP 8.4 trait-collision guard — NEVER public string $queue (Phase 5 Plan 02 lesson).
        // 260719-wth — dedicated single-worker write queue (product-create is a live
        // Woo write; keep it off the shared sync-woo-push pool).
        $this->onQueue('woo-writes');
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
        ?WooBrandCreator $brandCreator = null,
    ): void {
        // 260702-qd8 — method injection supplies the creator on the queue path;
        // the nullable default keeps the existing direct-call test harness green.
        $brandCreator ??= app(WooBrandCreator::class);

        // ── Throttle preflight (260822-rmo) ─────────────────────────────────
        // MUST come before the Product::create below. handle() builds local
        // state first, and the AUTO-08 duplicate gate would reject that very
        // row on a re-run — so deferring at the POST would leave the product
        // stranded local-only. Defer here, before anything is written.
        if ($this->releaseIfWooWriteWindowClosed(['sku' => $this->sku])) {
            return;
        }

        event(new AutoCreateAttempted($this->sku));

        // ── Duplicate gate (AUTO-08) ────────────────────────────────────────
        // 260824-vkc — the gate must tell "someone already stocks this part"
        // apart from "this is MY OWN row from a run that died after creating
        // it". Before this, a throttle at the Woo POST below stranded the
        // product local-only FOREVER: the retry matched the half-created row,
        // recorded reason=duplicate, and gave up. The same bug broke Replay
        // (AutoCreateRetryApplier) for every failure occurring after the create.
        $resumable = $this->findResumableOrphan();

        if ($resumable === null && $matcher->existsNormalised($this->sku)) {
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
        // On a resume, keep the slug the orphan already holds: it was never
        // POSTed so it is still free on Woo, and regenerating would append a
        // "-2" suffix by colliding with the orphan's own row.
        $uniqueSlug = ($resumable !== null && (string) $resumable->slug !== '')
            ? (string) $resumable->slug
            : $slugGenerator->generate($compiled['title'], $this->sku);

        // ── Pre-POST Woo slug collision probe (Pitfall P6-G) ────────────────
        $uniqueSlug = $this->ensureSlugFreeOnWoo($woo, $uniqueSlug);

        // ── Taxonomy resolution (AUTO-02) ───────────────────────────────────
        $brandId = $taxonomy->resolveBrand((string) ($supplierData['brand'] ?? ''));
        // 260702-qd8 — brand not on Woo yet? auto-create it (normalised, junk-guarded)
        // so a real brand no longer forces the needs-assignment park. Junk/blank/failed
        // → stays null → existing short-circuit still applies.
        if ($brandId === null && (bool) config('product_auto_create.auto_create_missing_brands', true)) {
            $brandId = $brandCreator->ensureBrandTermId((string) ($supplierData['brand'] ?? ''));
        }
        $categoryId = $taxonomy->resolveCategory((string) ($supplierData['category'] ?? ''));

        // A resumed row may already carry brand/category an operator assigned
        // by hand in the review inbox. Automatic resolution returning null must
        // not throw that away and re-park the row — acting on that assignment is
        // exactly what Replay is normally invoked to do.
        if ($resumable !== null) {
            $brandId ??= $resumable->brand_id !== null ? (int) $resumable->brand_id : null;
            $categoryId ??= $resumable->category_id !== null ? (int) $resumable->category_id : null;
        }

        $buyPennies = (int) round(((float) ($supplierData['price'] ?? 0)) * 100);

        // ── Create local Product row ───────────────────────────────────────
        $autoCreateStatus = ($brandId === null || $categoryId === null)
            ? 'needs_brand_or_category_assignment'
            : 'draft';

        $attributes = [
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
        ];

        $product = $resumable !== null
            ? $this->resumeOrphan($resumable, $attributes)
            : Product::create($attributes);

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
            // Read from $product, not $compiled: on a resume the ROW is the
            // truth (it may carry operator edits), and on a fresh create the
            // two are identical — so the normal path is unchanged.
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'status' => 'draft',  // AUTO-07 draft-first lock
            'type' => 'simple',
            'sku' => $this->sku,
            'regular_price' => $sellPennies > 0
                ? (string) number_format($sellPennies / 100, 2, '.', '')
                : '0.00',
            'short_description' => (string) $product->short_description,
            'description' => (string) $product->long_description,
            'meta_data' => [
                ['key' => '_yoast_wpseo_metadesc', 'value' => (string) $product->meta_description],
            ],
            'categories' => [['id' => $categoryId]],
            'images' => [],
        ];

        // Deferring here is safe ONLY because the duplicate gate above can now
        // resume: the local row survives, the retry finds it, and the POST is
        // re-attempted rather than rejected as its own duplicate.
        try {
            $response = $woo->post('/products', $payload);
        } catch (WooWriteThrottleException $e) {
            $this->releaseForWooThrottle($e, [
                'sku' => $this->sku,
                'product_id' => $product->id,
                'stage' => 'woo_create',
            ]);

            return;
        }

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
     * 260824-vkc — the local row left behind by a run that died AFTER creating
     * it but BEFORE the Woo POST landed.
     *
     * This is what makes the AUTO-08 duplicate gate safe to retry. Without it a
     * throttle at the POST was terminal: the retry saw the job's own half-made
     * row, called it a duplicate, and the product stayed local-only forever with
     * woo_product_id = null — the exact symptom reported on 2026-08-23
     * ("products sent to be created in the Woo store never appear"). It also
     * broke Replay, since AutoCreateRetryApplier re-dispatches THIS job and every
     * post-create failure therefore replayed straight into 'duplicate'.
     *
     * Deliberately narrow. A row qualifies only when all three hold:
     *
     *   - it is not on Woo yet (woo_product_id IS NULL) — anything with an id is
     *     already live and re-POSTing it WOULD duplicate it
     *   - it came from the auto-create pipeline, via the canonical autoCreated()
     *     scope (260606-mx9: `whereNotNull` is vacuous — the column defaults to
     *     'manual' — and AutoCreatedPredicateTest fails CI if that returns)
     *   - no ALTERNATIVE supplier code maps this SKU to a DIFFERENT product
     *
     * That last clause is the one that keeps 260823-clp intact: if an alias says
     * this part is already stocked under another product, the orphan is itself
     * the mistake, and resuming would put a second listing of one physical part
     * on the storefront. Refuse, and let the duplicate gate do its job.
     */
    private function findResumableOrphan(): ?Product
    {
        $orphan = Product::query()
            ->autoCreated()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($this->sku))])
            ->whereNull('woo_product_id')
            ->orderBy('id')
            ->first();

        if ($orphan === null) {
            return null;
        }

        $aliasedElsewhere = ProductSupplierSku::query()
            ->where('normalised_sku', ProductSupplierSku::normalise($this->sku))
            ->where('product_id', '!=', $orphan->id)
            ->exists();

        return $aliasedElsewhere ? null : $orphan;
    }

    /**
     * Re-use the orphan instead of creating a second row.
     *
     * Compiled content is REGENERATED from supplier data on every run, so
     * re-filling it wholesale would silently revert copy an operator edited in
     * the review inbox — a resumed row has by definition been sitting in that
     * inbox, visible and editable. So: fill blanks, always refresh buy_price
     * (supplier cost genuinely moves between the failed run and the retry), and
     * leave every populated operator-facing field exactly as it stands.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resumeOrphan(Product $orphan, array $attributes): Product
    {
        $operatorEditable = [
            'name',
            'slug',
            'short_description',
            'long_description',
            'meta_description',
        ];

        $preserved = [];
        foreach ($operatorEditable as $field) {
            if (trim((string) $orphan->{$field}) !== '') {
                unset($attributes[$field]);
                $preserved[] = $field;
            }
        }

        // Identity is already correct and must never be rewritten.
        unset($attributes['sku']);

        $orphan->forceFill($attributes)->save();

        Log::info('CreateWooProductJob: resumed an interrupted run', [
            'sku' => $this->sku,
            'product_id' => $orphan->id,
            'auto_create_status' => $attributes['auto_create_status'] ?? null,
            'preserved_fields' => $preserved,
            'correlation_id' => Context::get('correlation_id'),
        ]);

        return $orphan->refresh();
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
