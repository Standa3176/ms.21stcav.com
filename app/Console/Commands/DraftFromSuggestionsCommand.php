<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Concerns\ResolvesWooBrandKey;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * products:draft-from-suggestions — bulk pre-filter for the auto-create pipeline.
 *
 * Pulls SKUs from the Suggestions table (kind=new_product_opportunity,
 * status=pending), joins each against supplier_products to get its
 * manufacturer, then filters to:
 *
 *   - sourceable (at least one supplier carries it)
 *   - brand exists on Woo (so PublishProductJob's brand link will resolve)
 *   - optionally, manufacturer matches the operator-supplied --brands list
 *
 * Then chains `products:generate-drafts` and `products:assign-taxonomy` on
 * the filtered batch. Optionally also runs `products:source-images`. Designed
 * for operator-paced batches like "draft the next 100 Yealink + Samsung
 * tonight, no images yet, I'll review before sourcing images".
 *
 * Always preview with --dry-run before spending real Claude money.
 *
 *   php artisan products:draft-from-suggestions --brands=Yealink,Samsung --limit=50 --dry-run
 *   php artisan products:draft-from-suggestions --brands=Yealink,Samsung --limit=50
 *   php artisan products:draft-from-suggestions --brands=Yealink --limit=20 --source-images
 *
 * Default is drafts + taxonomy only (no images) — fastest signal at lowest
 * cost. Opt in to images with --source-images, or run `products:source-images`
 * separately on the keepers after operator review.
 */
final class DraftFromSuggestionsCommand extends BaseCommand
{
    // Quick task 260702-h50 — resolveBrandKey + firstResolvableBrandKey now
    // live in this shared trait (extracted verbatim; behaviour unchanged).
    use ResolvesWooBrandKey;

    /** Per-product Claude cost ballpark in pence (for the operator estimate). */
    private const COST_DRAFTS_PENCE = 2;

    private const COST_TAXONOMY_PENCE = 1;

    private const COST_IMAGES_PENCE = 10;

    protected $signature = 'products:draft-from-suggestions
        {--brands= : Comma-separated case-insensitive brand filter. Default: all brands on Woo.}
        {--skus= : Comma-separated explicit SKU list. When set, bypass the Suggestion walk and use these SKUs directly (still filtered to sourceable + brand-on-Woo). Used by the Filament "Auto-create all in this tab" header action.}
        {--min-competitors= : Suggestion-walk path only — include only SKUs whose evidence.supporting_competitors is >= this value (inclusive). Default: no lower bound (backward compatible).}
        {--max-competitors= : Suggestion-walk path only — include only SKUs whose evidence.supporting_competitors is <= this value (inclusive). Default: no upper bound (backward compatible).}
        {--limit=100 : Max products to include in this batch (0 = unbounded — careful)}
        {--source-images : Also run products:source-images at the end (default: skip — cheaper, run on keepers later)}
        {--auto-approve : Bypass the review inbox — auto-dispatch PublishProductJob to push each draft live on Woo (requires WOO_WRITE_ENABLED=true)}
        {--create-missing-brands : Auto-create the Woo brand term for brand_not_on_woo SKUs (normalised + junk-guarded) instead of skipping them.}
        {--no-confirm : Skip the interactive confirmation prompt (queue/job invocation). Implicitly set when stdin is non-interactive.}
        {--result-cache-key= : Internal — when set, write the run summary array to this cache key (TTL 600s) for the dispatching job to read}
        {--dry-run : Print the SKU list + cost estimate, do not call generate-drafts}';

    protected $description = 'Pre-filter Suggestions → chain generate-drafts + assign-taxonomy for sourceable + brand-on-Woo products.';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
        // 260702-qd8 — used to find-or-create the Woo brand for brand_not_on_woo
        // SKUs when --create-missing-brands is set.
        private readonly WooBrandCreator $brandCreator,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $brandsFilter = $this->parseBrandsFilter((string) ($this->option('brands') ?? ''));
        $limit = max(0, (int) $this->option('limit'));
        $sourceImages = (bool) $this->option('source-images');
        $autoApprove = (bool) $this->option('auto-approve');
        $createMissingBrands = (bool) $this->option('create-missing-brands');
        $dryRun = (bool) $this->option('dry-run');
        // 260711-aps — inclusive competitor-count band on the Suggestion-walk path.
        // null = no bound (backward compatible); applies to evidence.supporting_competitors.
        $minCompetitors = $this->option('min-competitors') !== null ? (int) $this->option('min-competitors') : null;
        $maxCompetitors = $this->option('max-competitors') !== null ? (int) $this->option('max-competitors') : null;
        $explicitSkus = $this->parseSkusOption((string) ($this->option('skus') ?? ''));
        $skipConfirm = (bool) $this->option('no-confirm')
            || ! $this->input->isInteractive();
        // 260711-aps — thread this run's correlation id (set by BaseCommand) onto
        // each audit-log row so a scheduled batch is traceable end-to-end.
        $correlationId = Context::get('correlation_id');
        $correlationId = is_string($correlationId) ? $correlationId : null;

        // ── 1. Resolve current Woo brand list (cached by TaxonomyResolver) ──
        $wooBrandsByLower = [];
        foreach ($this->taxonomy->allBrands() as $b) {
            $name = trim((string) ($b['name'] ?? ''));
            if ($name !== '') {
                $wooBrandsByLower[mb_strtolower($name)] = $name;
            }
        }
        if ($wooBrandsByLower === []) {
            $this->error('No Woo brand terms found — cannot filter. Aborting.');

            return SymfonyCommand::FAILURE;
        }
        $this->info('Loaded '.count($wooBrandsByLower).' Woo brand terms.');

        // ── 2. Validate --brands filter against the Woo brand set ──
        if ($brandsFilter !== null) {
            $missing = array_diff($brandsFilter, array_keys($wooBrandsByLower));
            if ($missing !== []) {
                $this->warn('Brand(s) not on Woo (will be ignored): '.implode(', ', $missing));
            }
            $brandsFilter = array_values(array_intersect($brandsFilter, array_keys($wooBrandsByLower)));
            if ($brandsFilter === []) {
                $this->error('No --brands entries match any Woo brand. Aborting.');

                return SymfonyCommand::FAILURE;
            }
            $canonicalNames = array_map(static fn (string $lc) => $wooBrandsByLower[$lc], $brandsFilter);
            $this->info('Filtering to '.count($brandsFilter).' brand(s): '.implode(', ', $canonicalNames));
        }

        // ── 3. Connect supplier_db ──
        $c = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new \mysqli(
            (string) $c['host'], (string) $c['username'], (string) $c['password'],
            (string) $c['database'], (int) ($c['port'] ?? 3306),
        );
        if ($m->connect_errno !== 0) {
            $this->error('supplier_db connect failed: '.$m->connect_error);

            return SymfonyCommand::FAILURE;
        }

        // ── 4. Walk pending suggestions in chunks; collect candidates ──
        /** @var array<string, string> $candidates  sku → canonical Woo brand name */
        $candidates = [];
        // 260711-aps — sku → driving suggestion's supporting_competitors, populated
        // during the Suggestion walk; consumed by the --auto-approve audit write.
        /** @var array<string, int> $competitorBySku */
        $competitorBySku = [];
        /** @var array<string, array<int, string>> $byBrand  canonical brand → [skus] */
        $byBrand = [];
        // Skip-reason buckets — survive across chunks via use (&$skips) below.
        // not_sourceable / no_manufacturer / brand_not_on_woo populated by
        // classifySkip(); brand_filtered holds candidates the operator's
        // explicit --brands list excluded.
        /** @var array{not_sourceable: array<int, string>, no_manufacturer: array<int, string>, brand_not_on_woo: array<int, string>, brand_filtered: array<int, string>} $skips */
        $skips = ['not_sourceable' => [], 'no_manufacturer' => [], 'brand_not_on_woo' => [], 'brand_filtered' => []];

        // Chunk processor — accepts an iterable of {evidence-shape stdClass} OR
        // can be invoked directly with an array of raw SKU strings via the
        // adapter below. Returns false to stop processing (limit reached).
        // 260702-qd8 — $wooBrandsByLower is captured BY REFERENCE so a brand
        // created for one SKU immediately lets sibling SKUs in later chunks
        // resolve without a second create.
        $chunkProcessor = function (iterable $skusInChunk) use ($m, &$wooBrandsByLower, $brandsFilter, &$candidates, &$byBrand, &$skips, $limit, $createMissingBrands): bool {
            if ($limit > 0 && count($candidates) >= $limit) {
                return false; // stop processing
            }
            $skus = [];
            foreach ($skusInChunk as $sku) {
                $sku = trim((string) $sku);
                if ($sku !== '' && ! isset($candidates[$sku])) {
                    $skus[] = $sku;
                }
            }
            if ($skus === []) {
                return true;
            }

            $ph = implode(',', array_fill(0, count($skus), '?'));
            $stmt = $m->prepare("SELECT suppliersku, mpn, manufacturer FROM supplier_products WHERE suppliersku IN ($ph) OR mpn IN ($ph)");
            $params = array_merge($skus, $skus);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            // Every matched suppliersku/mpn (regardless of manufacturer) — lets
            // classifySkip() distinguish "not in feed at all" (not_sourceable)
            // from "in feed but blank manufacturer" (no_manufacturer). Keys are
            // LOWER(TRIM())'d by indexSupplierRows() so space-padded CHAR feed
            // columns match the trimmed evidence SKU (bug 260703-rk3).
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            ['seen' => $seenInFeed, 'mfrs' => $supMap] = $this->indexSupplierRows($rows);

            foreach ($skus as $sku) {
                if ($limit > 0 && count($candidates) >= $limit) {
                    return false;
                }
                $key = strtolower($sku);
                $inFeed = isset($seenInFeed[$key]);
                $mfrs = $supMap[$key] ?? [];          // list of manufacturers now
                $hasMfr = $mfrs !== [];
                // Multi-row case: prefer the manufacturer that resolves to a Woo
                // brand over a non-brand add-on (e.g. a "Protect Plus" warranty row
                // sharing the MPN). For the common single-manufacturer SKU this is a
                // one-element list, so it behaves exactly as before.
                [$brandKey, $matchedMfr] = $this->firstResolvableBrandKey($mfrs, $wooBrandsByLower);
                $reason = $this->classifySkip($inFeed, $hasMfr, $brandKey !== null);
                if ($reason !== null) {
                    if ($reason === 'brand_not_on_woo') {
                        // 260702-qd8 — with --create-missing-brands, find-or-create
                        // the Woo brand term (normalised + junk-guarded) and promote
                        // the SKU to a candidate instead of skipping it. The new brand
                        // is added to the in-memory map (by-ref) so sibling SKUs
                        // resolve without a second create.
                        $canonical = $this->promoteMissingBrand($mfrs, $createMissingBrands);
                        if ($canonical !== null) {
                            $wooBrandsByLower[mb_strtolower($canonical)] = $canonical;
                            $candidates[$sku] = $canonical;
                            $byBrand[$canonical][] = $sku;

                            continue;
                        }
                        // List a representative manufacturer + sku so the operator
                        // knows which brand to add.
                        $skips['brand_not_on_woo'][] = ($mfrs[0] ?? '?').' ('.$sku.')';
                    } else {
                        $skips[$reason][] = $sku;
                    }

                    continue;
                }
                if ($brandsFilter !== null && ! in_array($brandKey, $brandsFilter, true)) {
                    $skips['brand_filtered'][] = $sku;

                    continue;
                }
                $canonical = $wooBrandsByLower[$brandKey];
                $candidates[$sku] = $canonical;
                $byBrand[$canonical][] = $sku;
            }

            return true;
        };

        // Source of SKUs: explicit --skus list (Filament tab bulk-action path)
        // OR walk the pending Suggestions table (default operator path).
        if ($explicitSkus !== []) {
            $this->info('Using --skus list ('.count($explicitSkus).' SKU(s) provided) — bypassing Suggestion walk.');
            foreach (array_chunk($explicitSkus, 200) as $skuChunk) {
                if ($chunkProcessor($skuChunk) === false) {
                    break;
                }
            }
        } else {
            $this->pendingOpportunitySuggestionsQuery($minCompetitors, $maxCompetitors)
                ->chunk(200, function ($rows) use ($chunkProcessor, &$competitorBySku) {
                    // Adapt suggestion rows → raw SKU strings for the processor.
                    // 260711-aps — also capture the driving suggestion's competitor
                    // count keyed by SKU so the --auto-approve audit-log write can
                    // record the 2-vs-3 split per published product.
                    $skus = [];
                    foreach ($rows as $sug) {
                        $ev = json_decode((string) $sug->evidence, true);
                        $sku = trim((string) ($ev['sku'] ?? ''));
                        if ($sku !== '') {
                            $skus[] = $sku;
                            $competitorBySku[$sku] = (int) ($ev['supporting_competitors'] ?? 0);
                        }
                    }

                    return $chunkProcessor($skus) === false ? false : null;
                });
        }

        $m->close();

        $skuList = array_keys($candidates);
        $count = count($skuList);
        if ($count === 0) {
            $this->info('No matching SKUs to draft.');
            $this->printSkipBreakdown($skips, $explicitSkus !== []);

            // 260630-ry6 — even a 0-created run reports WHY (skip buckets) so the
            // dispatching job's notification explains a black-box no-op.
            $this->writeRunSummary([
                'created' => 0,
                'created_skus' => [],
                'by_brand' => [],
                'skipped' => [
                    'not_sourceable' => array_values($skips['not_sourceable'] ?? []),
                    'no_manufacturer' => array_values($skips['no_manufacturer'] ?? []),
                    'brand_not_on_woo' => array_values($skips['brand_not_on_woo'] ?? []),
                ],
                'auto_publish' => null,
            ]);

            return SymfonyCommand::SUCCESS;
        }

        // ── 5. Summary + cost estimate ──
        ksort($byBrand);
        $this->newLine();
        $this->info("Batch: {$count} product(s) across ".count($byBrand).' brand(s)');
        foreach ($byBrand as $brand => $skus) {
            $this->line('  '.str_pad((string) count($skus), 5, ' ', STR_PAD_LEFT).'  '.$brand);
        }
        $this->printSkipBreakdown($skips, $explicitSkus !== []);

        $perProductPence = self::COST_DRAFTS_PENCE + self::COST_TAXONOMY_PENCE + ($sourceImages ? self::COST_IMAGES_PENCE : 0);
        $totalPence = $count * $perProductPence;
        $this->newLine();
        $stages = ['drafts', 'taxonomy'];
        if ($sourceImages) {
            $stages[] = 'images';
        }
        if ($autoApprove) {
            $stages[] = 'auto-publish to Woo';
        }
        $this->info(sprintf(
            'Estimated Claude spend: ~%dp (~£%s) at ~%dp/product [%s]',
            $totalPence, number_format($totalPence / 100, 2), $perProductPence,
            implode(' + ', $stages),
        ));
        if ($autoApprove) {
            $this->warn('⚠ --auto-approve will publish all '.$count.' product(s) DIRECTLY to live Woo storefront. Customers will see them immediately. No review gate.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run — exiting without dispatching.');

            return SymfonyCommand::SUCCESS;
        }

        // ── 6. Confirm before spending real money / writing to live store ──
        // Skipped when --no-confirm is passed (queued job invocation path) or
        // when stdin is non-interactive (e.g. cron, Horizon worker, CI).
        if (! $skipConfirm) {
            $confirmMsg = $autoApprove
                ? "PUBLISH {$count} product(s) to LIVE Woo storefront? (auto-approve)"
                : "Proceed with chained run on {$count} product(s)?";
            if (! $this->confirm($confirmMsg, false)) {
                $this->warn('Aborted by operator.');

                return SymfonyCommand::FAILURE;
            }
        } else {
            $this->info('--no-confirm / non-interactive — proceeding without prompt.');
        }

        $skusCsv = implode(',', $skuList);

        // ── 7. Chain the pipeline. Each child command prints its own progress ──
        $this->newLine();
        $this->info('==> products:generate-drafts');
        $this->call('products:generate-drafts', ['--skus' => $skusCsv]);

        // ── 7a. Mark matching suggestions as applied ───────────────────
        // generate-drafts creates a local Product row per SKU it handled.
        // The corresponding Suggestion (kind=new_product_opportunity,
        // status=pending) should flip to status=applied so the same SKU
        // is not re-surfaced on the next batch. Without this, every
        // run of products:draft-from-suggestions used to silently re-
        // process already-drafted SKUs and pending stayed flat at 14,101
        // (operator hit this 2026-06-01 and had to backfill 330 rows by
        // hand — see commits 26e7e01 / 60cb7e2 era).
        // Match on evidence.sku via JSON_EXTRACT. Only marks suggestions
        // for SKUs whose local Product actually exists after drafting
        // (so we never falsely mark applied on a Claude failure or
        // supplier-row miss — those products won't be in $createdSkus).
        $createdSkus = Product::whereIn('sku', $skuList)->pluck('sku')->toArray();
        $marked = Suggestion::where('kind', 'new_product_opportunity')
            ->where('status', 'pending')
            ->whereIn(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(evidence, "$.sku"))'),
                $createdSkus,
            )
            ->update(['status' => 'applied']);
        $this->info(sprintf(
            '==> marked %d suggestion(s) as applied (was pending)',
            $marked,
        ));

        $this->newLine();
        $this->info('==> products:assign-taxonomy');
        $this->call('products:assign-taxonomy', ['--skus' => $skusCsv]);

        if ($sourceImages) {
            $this->newLine();
            $this->info('==> products:source-images');
            $this->call('products:source-images', ['--skus' => $skusCsv]);
        } else {
            $this->newLine();
            $this->info('Images skipped — run `products:source-images --skus=...` on keepers after review.');
        }

        // ── 8. Optional: auto-approve → dispatch PublishProductJob per draft ──
        // Declared here (not inside the block) so the run-summary at the final
        // return can reference them regardless of the --auto-approve path.
        $published = 0;
        $shadowed = 0;
        $failed = 0;
        if ($autoApprove) {
            $this->newLine();
            $this->info('==> auto-publish to Woo (PublishProductJob per draft)');
            foreach ($skuList as $sku) {
                $product = Product::where('sku', $sku)->first();
                if ($product === null) {
                    $this->warn("  ✗ {$sku}: local product missing — skipped");
                    $failed++;

                    continue;
                }
                try {
                    // dispatchSync runs the job inline, so we can capture the
                    // back-fill of woo_product_id without waiting on Horizon.
                    PublishProductJob::dispatchSync((int) $product->id, 0);
                    // 260711-aps — write the audit row ONLY on a confirmed real
                    // publish (recordAutoPublish re-reads auto_create_status +
                    // woo_product_id). In shadow mode both are absent → returns
                    // false → NO row, and the product stays in the review inbox.
                    $wrote = $this->recordAutoPublish(
                        $product,
                        $competitorBySku[$sku] ?? 0,
                        null,
                        $correlationId,
                    );
                    if ($wrote) {
                        $product->refresh();
                        $this->line(sprintf('  ✓ %s → Woo #%d (audit logged)', $sku, $product->woo_product_id));
                        $published++;
                    } else {
                        // No confirmed publish = shadow mode (WOO_WRITE_ENABLED=false)
                        // or some other no-op path. Stays in review inbox; no audit row.
                        $this->warn("  ⊘ {$sku}: not confirmed published (shadow mode?) — stays in review inbox, no audit row");
                        $shadowed++;
                    }
                } catch (\Throwable $e) {
                    $this->warn(sprintf('  ✗ %s: %s', $sku, $e->getMessage()));
                    $failed++;
                }
            }
            $this->newLine();
            $this->info(sprintf(
                'Auto-publish complete: %d live on Woo, %d shadowed, %d failed.',
                $published, $shadowed, $failed,
            ));
        }

        $this->newLine();
        if ($autoApprove) {
            $this->info("Done. Batch of {$count} processed end-to-end.");
        } else {
            $this->info("Done. {$count} draft(s) ready in /admin/auto-create-reviews.");
        }

        // 260630-ry6 — structured run summary for the dispatching job's
        // rich completion notification (no-op on the CLI path: no cache key).
        $this->writeRunSummary([
            'created' => $count,
            'created_skus' => array_values($skuList),
            'by_brand' => array_map('count', $byBrand),
            'skipped' => [
                'not_sourceable' => array_values($skips['not_sourceable'] ?? []),
                'no_manufacturer' => array_values($skips['no_manufacturer'] ?? []),
                'brand_not_on_woo' => array_values($skips['brand_not_on_woo'] ?? []),
            ],
            'auto_publish' => $autoApprove
                ? ['published' => $published, 'shadowed' => $shadowed, 'failed' => $failed]
                : null,
        ]);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * 260630-ry6 — persist the run summary so the dispatching
     * RunAutoCreatePipelineJob can build a per-SKU completion notification.
     * No-op on the CLI path (no --result-cache-key passed) — keeps interactive
     * `php artisan products:draft-from-suggestions` behaviour byte-identical.
     *
     * @param  array<string,mixed>  $summary
     */
    private function writeRunSummary(array $summary): void
    {
        $key = (string) $this->option('result-cache-key');
        if ($key !== '') {
            Cache::put($key, $summary, 600);
        }
    }

    /**
     * 260711-aps — the pending new_product_opportunity walk query, with the
     * optional inclusive competitor-count band applied to
     * evidence.supporting_competitors. This is the exact query the Suggestion-
     * walk path drives; exposed (public) so the competitor filter is testable
     * without the live supplier_db mysqli walk.
     *
     * Driver-portable (memory: SQLite↔MariaDB strict trap): SQLite uses
     * json_extract() (already unquotes scalars) + CAST(... AS INTEGER); MariaDB
     * (prod) needs JSON_UNQUOTE(JSON_EXTRACT(...)) + CAST(... AS SIGNED). No
     * MySQL-only CAST AS UNSIGNED in a shared (non-switched) path.
     *
     * min/max null = no bound on that side (both null = no filter at all, so
     * behaviour is byte-identical to the pre-260711 walk).
     */
    public function pendingOpportunitySuggestionsQuery(?int $minCompetitors, ?int $maxCompetitors): Builder
    {
        $query = DB::table('suggestions')
            ->where('kind', 'new_product_opportunity')
            ->where('status', 'pending')
            ->orderBy('id');

        if ($minCompetitors !== null || $maxCompetitors !== null) {
            $expr = $this->competitorCountExpr();
            if ($minCompetitors !== null) {
                $query->whereRaw("{$expr} >= ?", [$minCompetitors]);
            }
            if ($maxCompetitors !== null) {
                $query->whereRaw("{$expr} <= ?", [$maxCompetitors]);
            }
        }

        return $query;
    }

    /**
     * Driver-portable integer extraction of evidence.supporting_competitors.
     * Mirrors Suggestion::scopeHighConfidenceSourceable (SQLite json_extract +
     * CAST AS INTEGER; MariaDB JSON_UNQUOTE(JSON_EXTRACT()) + CAST AS SIGNED).
     */
    private function competitorCountExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(json_extract(evidence, '$.supporting_competitors') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS SIGNED)";
    }

    /**
     * 260711-aps — record ONE auto_publish_log row for a confirmed REAL live
     * publish, then return true. Returns false (and writes NOTHING) unless the
     * product is confirmed published on Woo: re-read auto_create_status must be
     * 'published' AND woo_product_id must be present. This is the seam that keeps
     * a shadow-mode run (WOO_WRITE_ENABLED=false — PublishProductJob no-ops,
     * leaving the row un-published with no woo id) from ever writing an audit row.
     *
     * competitorCount is the driving suggestion's supporting_competitors (2 or 3
     * under the schedule) so the operator sees the split. supplierCount is
     * forward-compat (the current walk passes null). Pure + injectable — the
     * --auto-approve loop calls it, and it is unit-tested without the live walk.
     */
    public function recordAutoPublish(Product $product, int $competitorCount, ?int $supplierCount, ?string $correlationId): bool
    {
        $product->refresh();

        if ($product->auto_create_status !== 'published') {
            return false;
        }
        $wooId = (int) ($product->woo_product_id ?? 0);
        if ($wooId <= 0) {
            return false;
        }

        AutoPublishLogEntry::create([
            'sku' => (string) $product->sku,
            'product_id' => (int) $product->id,
            'woo_product_id' => $wooId,
            'competitor_count' => $competitorCount,
            'supplier_count' => $supplierCount,
            'source' => AutoPublishLogEntry::SOURCE_SCHEDULED,
            'batch_correlation_id' => $correlationId,
            'published_at' => now(),
        ]);

        return true;
    }

    /**
     * Build the in-feed membership set + manufacturer map from supplier_products
     * rows. Keys are LOWER(TRIM()) of suppliersku/mpn: the supplier feed stores
     * these as space-padded CHAR columns ("49XE4F-M            "), so WITHOUT trim()
     * the key never equals the trimmed evidence SKU and the row is wrongly classed
     * not_sourceable (bug 2026-07-03: LG/panel SKUs skipped despite being in feed).
     * Mirrors supplier_sku_cache's LOWER(TRIM()) keying. Pure; unit-tested.
     *
     * A SKU can match multiple feed rows under one MPN with different manufacturers
     * (e.g. a product row + a warranty/protection-plan row). Collect ALL of them —
     * append + dedup — so the per-SKU loop can prefer the one that resolves to a
     * Woo brand instead of the arbitrary last-fetched value.
     *
     * @param  iterable<int, array<string,mixed>>  $rows  supplier_products rows (suppliersku, mpn, manufacturer)
     * @return array{seen: array<string,bool>, mfrs: array<string,array<int,string>>}
     */
    public function indexSupplierRows(iterable $rows): array
    {
        $seen = [];
        $mfrs = [];
        foreach ($rows as $r) {
            $ssku = strtolower(trim((string) ($r['suppliersku'] ?? '')));
            $mpn = strtolower(trim((string) ($r['mpn'] ?? '')));
            foreach ([$ssku, $mpn] as $k) {
                if ($k !== '') {
                    $seen[$k] = true;
                }
            }
            $mfr = trim((string) ($r['manufacturer'] ?? ''));
            if ($mfr === '') {
                continue;
            }
            foreach ([$ssku, $mpn] as $k) {
                if ($k === '') {
                    continue;
                }
                $mfrs[$k] ??= [];
                if (! in_array($mfr, $mfrs[$k], true)) {
                    $mfrs[$k][] = $mfr;
                }
            }
        }

        return ['seen' => $seen, 'mfrs' => $mfrs];
    }

    /**
     * 260702-qd8 — decide whether a brand_not_on_woo SKU can be PROMOTED to a
     * candidate by auto-creating its Woo brand. Returns the canonical
     * (normalised) brand name when --create-missing-brands is set AND
     * WooBrandCreator successfully find-or-created the (non-junk) term; null
     * otherwise (flag off, junk/blank name, writes disabled, or create failed) —
     * in which case the SKU stays in the brand_not_on_woo skip bucket.
     *
     * Pure of DB/mysqli (the WooBrandCreator dependency is injectable/mockable),
     * so the promotion decision is unit-testable without the supplier walk.
     *
     * @param  array<int,string>  $mfrs  the SKU's feed manufacturers (first = brand to add)
     */
    public function promoteMissingBrand(array $mfrs, bool $createMissingBrands): ?string
    {
        if (! $createMissingBrands) {
            return null;
        }

        $raw = $mfrs[0] ?? '';
        $newId = $this->brandCreator->ensureBrandTermId($raw);
        if ($newId === null) {
            return null;
        }

        return $this->brandCreator->normaliseBrandName((string) $raw);
    }

    /**
     * Why was a SKU skipped (or null if it's a valid candidate)?
     *   not_sourceable   — no supplier feed row at all
     *   no_manufacturer  — feed row exists but manufacturer blank
     *   brand_not_on_woo — manufacturer present but not a Woo brand
     */
    public function classifySkip(bool $inFeed, bool $hasManufacturer, bool $brandResolved): ?string
    {
        if (! $inFeed) {
            return 'not_sourceable';
        }
        if (! $hasManufacturer) {
            return 'no_manufacturer';
        }
        if (! $brandResolved) {
            return 'brand_not_on_woo';
        }

        return null;
    }

    /**
     * Print the per-bucket skip breakdown. On the explicit --skus path also
     * lists each skipped SKU + reason (the operator picked them, wants detail);
     * on the walk path prints per-bucket counts only (could be thousands).
     *
     * @param  array{not_sourceable: array<int, string>, no_manufacturer: array<int, string>, brand_not_on_woo: array<int, string>, brand_filtered: array<int, string>}  $skips
     */
    private function printSkipBreakdown(array $skips, bool $explicit): void
    {
        $totalSkipped = array_sum(array_map('count', $skips));
        if ($totalSkipped <= 0) {
            return;
        }
        $this->newLine();
        $this->warn("Skipped {$totalSkipped} SKU(s):");
        if (! empty($skips['not_sourceable'])) {
            $this->line('  not sourceable (no supplier carries it): '.count($skips['not_sourceable']));
        }
        if (! empty($skips['no_manufacturer'])) {
            $this->line('  no manufacturer in feed: '.count($skips['no_manufacturer']));
        }
        if (! empty($skips['brand_not_on_woo'])) {
            $this->line('  brand not on Woo (add under Products → Brands): '.count($skips['brand_not_on_woo']));
        }
        if (! empty($skips['brand_filtered'])) {
            $this->line('  excluded by --brands filter: '.count($skips['brand_filtered']));
        }
        // Explicit --skus path: list each skipped SKU + reason (operator picked them).
        if ($explicit) {
            foreach (['not_sourceable' => 'not sourceable', 'no_manufacturer' => 'no manufacturer', 'brand_not_on_woo' => 'brand not on Woo'] as $bk => $label) {
                foreach ($skips[$bk] ?? [] as $entry) {
                    $this->line("    - {$entry}: {$label}");
                }
            }
        }
    }

    /**
     * Parse --brands=A,B,C into a lowercased array. Returns null when filter is empty.
     *
     * @return array<int, string>|null
     */
    private function parseBrandsFilter(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        );

        return array_values(array_unique(array_map('mb_strtolower', $parts)));
    }

    /**
     * Parse the comma-separated --skus list. Returns [] when empty (operator
     * is using the default Suggestion-walk path). Returns deduped trimmed
     * SKU strings — case is PRESERVED here because SKUs are case-sensitive
     * in Woo + supplier_db (downstream lookups handle their own normalisation).
     *
     * @return array<int, string>
     */
    private function parseSkusOption(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        );

        return array_values(array_unique($parts));
    }
}
