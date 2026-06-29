<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
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
    /** Per-product Claude cost ballpark in pence (for the operator estimate). */
    private const COST_DRAFTS_PENCE = 2;

    private const COST_TAXONOMY_PENCE = 1;

    private const COST_IMAGES_PENCE = 10;

    protected $signature = 'products:draft-from-suggestions
        {--brands= : Comma-separated case-insensitive brand filter. Default: all brands on Woo.}
        {--skus= : Comma-separated explicit SKU list. When set, bypass the Suggestion walk and use these SKUs directly (still filtered to sourceable + brand-on-Woo). Used by the Filament "Auto-create all in this tab" header action.}
        {--limit=100 : Max products to include in this batch (0 = unbounded — careful)}
        {--source-images : Also run products:source-images at the end (default: skip — cheaper, run on keepers later)}
        {--auto-approve : Bypass the review inbox — auto-dispatch PublishProductJob to push each draft live on Woo (requires WOO_WRITE_ENABLED=true)}
        {--no-confirm : Skip the interactive confirmation prompt (queue/job invocation). Implicitly set when stdin is non-interactive.}
        {--dry-run : Print the SKU list + cost estimate, do not call generate-drafts}';

    protected $description = 'Pre-filter Suggestions → chain generate-drafts + assign-taxonomy for sourceable + brand-on-Woo products.';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $brandsFilter = $this->parseBrandsFilter((string) ($this->option('brands') ?? ''));
        $limit = max(0, (int) $this->option('limit'));
        $sourceImages = (bool) $this->option('source-images');
        $autoApprove = (bool) $this->option('auto-approve');
        $dryRun = (bool) $this->option('dry-run');
        $explicitSkus = $this->parseSkusOption((string) ($this->option('skus') ?? ''));
        $skipConfirm = (bool) $this->option('no-confirm')
            || ! $this->input->isInteractive();

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
        /** @var array<string, array<int, string>> $byBrand  canonical brand → [skus] */
        $byBrand = [];

        // Chunk processor — accepts an iterable of {evidence-shape stdClass} OR
        // can be invoked directly with an array of raw SKU strings via the
        // adapter below. Returns false to stop processing (limit reached).
        $chunkProcessor = function (iterable $skusInChunk) use ($m, $wooBrandsByLower, $brandsFilter, &$candidates, &$byBrand, $limit): bool {
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
            $supMap = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $mfr = trim((string) $r['manufacturer']);
                if ($mfr === '') {
                    continue;
                }
                $supMap[strtolower((string) $r['suppliersku'])] = $mfr;
                $supMap[strtolower((string) $r['mpn'])] = $mfr;
            }
            $stmt->close();

            foreach ($skus as $sku) {
                if ($limit > 0 && count($candidates) >= $limit) {
                    return false;
                }
                $key = strtolower($sku);
                if (! isset($supMap[$key])) {
                    continue;
                }
                $mfrLower = mb_strtolower($supMap[$key]);
                $brandKey = $this->resolveBrandKey($mfrLower, $wooBrandsByLower);
                if ($brandKey === null) {
                    continue;
                }
                if ($brandsFilter !== null && ! in_array($brandKey, $brandsFilter, true)) {
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
            DB::table('suggestions')
                ->where('kind', 'new_product_opportunity')
                ->where('status', 'pending')
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($chunkProcessor) {
                    // Adapt suggestion rows → raw SKU strings for the processor.
                    $skus = [];
                    foreach ($rows as $sug) {
                        $ev = json_decode((string) $sug->evidence, true);
                        $sku = trim((string) ($ev['sku'] ?? ''));
                        if ($sku !== '') {
                            $skus[] = $sku;
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

            return SymfonyCommand::SUCCESS;
        }

        // ── 5. Summary + cost estimate ──
        ksort($byBrand);
        $this->newLine();
        $this->info("Batch: {$count} product(s) across ".count($byBrand).' brand(s)');
        foreach ($byBrand as $brand => $skus) {
            $this->line('  '.str_pad((string) count($skus), 5, ' ', STR_PAD_LEFT).'  '.$brand);
        }

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
        if ($autoApprove) {
            $this->newLine();
            $this->info('==> auto-publish to Woo (PublishProductJob per draft)');
            $published = 0;
            $shadowed = 0;
            $failed = 0;
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
                    $product->refresh();
                    if ($product->woo_product_id !== null && (int) $product->woo_product_id > 0) {
                        $this->line(sprintf('  ✓ %s → Woo #%d', $sku, $product->woo_product_id));
                        $published++;
                    } else {
                        // No woo_product_id back-filled = shadow mode (WOO_WRITE_ENABLED=false)
                        // or some other no-op path. Stays in review inbox.
                        $this->warn("  ⊘ {$sku}: no Woo id back-filled (shadow mode?) — stays in review inbox");
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

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Resolve a feed manufacturer string to a Woo brand KEY (lowercased), or null.
     *
     * Feed manufacturers are frequently "Brand - Category" shaped (e.g.
     * "Yealink - Headset"), which never equals the clean "Yealink" brand term.
     * Strategy: exact match first (preserves all current behaviour); on miss,
     * strip a trailing " - <suffix>" segment and retry. Conservative — only the
     * " - " (space-hyphen-space) separator is treated as a category suffix.
     *
     * @param  array<string,string>  $wooBrandsByLower  lowercased-name => canonical-name
     */
    public function resolveBrandKey(string $mfrLower, array $wooBrandsByLower): ?string
    {
        $mfrLower = trim($mfrLower);
        if ($mfrLower === '') {
            return null;
        }

        // 1. Exact (current behaviour).
        if (isset($wooBrandsByLower[$mfrLower])) {
            return $mfrLower;
        }

        // 2. Strip a trailing " - <suffix>": take the segment before the FIRST
        //    " - " so "yealink - headset - uk" → "yealink". Retry.
        if (str_contains($mfrLower, ' - ')) {
            $lead = trim(explode(' - ', $mfrLower, 2)[0]);
            if ($lead !== '' && isset($wooBrandsByLower[$lead])) {
                return $lead;
            }
        }

        return null;
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
