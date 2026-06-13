<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Services\BrandDuplicateFinder;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260613-f2r — brands:retag-products-on-woo.
 *
 * Closes the Woo-side gap left by 260613-dir's brands:dedupe. After 260613-dir,
 * MS products are perfectly consolidated onto canonical brand_ids, but Woo
 * still has products tagged with the duplicate (source) brand terms. Running
 * `brands:dedupe --delete-empty-woo-terms` WITHOUT this command first would
 * strip the brand association from those products via Woo's ?force=true
 * cascade — they'd become brand-less on the storefront.
 *
 * This command re-tags each affected Woo product from source → canonical
 * FIRST, so the source brands legitimately have count=0 on Woo afterwards
 * and the delete operation is safe.
 *
 * **Operator workflow:**
 *   1. brands:dedupe                              — MS-side merge (260613-dir)
 *   2. brands:retag-products-on-woo               — Woo-side re-tag (this command)
 *   3. brands:dedupe --delete-empty-woo-terms     — safely delete empty source terms
 *
 * **Per-product re-tag PRESERVES non-source brand tags:** if a product has
 * [source, otherBrand], the new tags are [canonical, otherBrand] — not
 * [canonical]. Computed as (current MINUS source) UNION canonical, then
 * unique + sorted for deterministic PUT bodies.
 *
 * **Drift-prevention:** ALL Woo writes via $this->woo (WooClient). Direct
 * Http:: / Guzzle / new AutomatticClient() in this command would bypass
 * IntegrationLogger audit trail + correlation_id threading.
 *
 * **Single source of truth:** brand-duplicate discovery flows through
 * BrandDuplicateFinder (260613-f2r). Do NOT re-implement pagination + grouping
 * + canonical-pick here — the service is the single seam shared with
 * DedupeBrandsCommand (260613-dir).
 *
 * **Scope contract:** this command's job is exactly "Re-tag Woo products from
 * source brand terms → canonical brand terms, per the [sourceId => canonicalId]
 * map produced by BrandDuplicateFinder." If a future quick task adds a 4th
 * brand surface (variations, attributes), do NOT extend this command — write
 * a sibling.
 *
 * **Idempotence:** re-running on already-retagged state is a no-op
 * (`products_retagged=0`, `already_canonical>0`, no Woo PUTs).
 *
 *   php artisan brands:retag-products-on-woo --dry-run
 *   php artisan brands:retag-products-on-woo --source-ids=12776,2904
 *   php artisan brands:retag-products-on-woo --limit=50
 *   php artisan brands:retag-products-on-woo
 */
// Not `final` so the Pest feature test can swap WooClient + BrandDuplicateFinder
// + Auditor via the container without subclassing the command itself (mirrors
// DedupeBrandsCommand + PushVisibilityToWooCommand pattern).
class RetagProductsOnWooCommand extends BaseCommand
{
    /**
     * Woo REST per-page cap. Grep-discoverable for future tuning.
     */
    private const PRODUCTS_PER_PAGE = 100;

    /**
     * 200ms pacing between live Woo PUTs. Mirrors PushVisibilityToWooCommand
     * line 167 + PushDivergenceToWooCommand cadence. WooClient's built-in
     * 429 backoff is the backstop; this throttle keeps bursty bulk operations
     * polite by default.
     */
    private const WOO_PUT_THROTTLE_USEC = 200_000;

    protected $signature = 'brands:retag-products-on-woo
        {--dry-run : Print per-source plan + 20-row sample without writing to Woo}
        {--source-ids= : Comma-separated source brand ids to scope; default = auto-discover all duplicates}
        {--limit=0 : Global cap on total products processed across all sources (0=unbounded)}';

    protected $description = 'Re-tag Woo products from duplicate (source) brand terms → canonical brand terms so brands:dedupe --delete-empty-woo-terms is safe (260613-f2r).';

    public function __construct(
        private readonly WooClient $woo,
        private readonly Auditor $auditor,
        private readonly BrandDuplicateFinder $finder,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        /** @var array<int, int> $explicitSourceIds */
        $explicitSourceIds = array_values(array_map(
            'intval',
            array_filter(
                array_map('trim', explode(',', (string) $this->option('source-ids'))),
                static fn (string $s): bool => $s !== '' && ctype_digit($s) && (int) $s > 0,
            ),
        ));

        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'brands:retag-products-on-woo — source_ids='
            .($explicitSourceIds === [] ? 'auto-discover' : implode(',', $explicitSourceIds))
            .' limit='.($limit === 0 ? 'unbounded' : (string) $limit)
        );

        // ── 2. Discover duplicates via BrandDuplicateFinder ──────────────────
        try {
            $rawPlan = $this->finder->discover();
        } catch (\Throwable $e) {
            $this->warn("  ! brands discovery failed: {$e->getMessage()}");
            $this->auditor->record('brands.retag_discovery_failed', [
                'error' => $e->getMessage(),
            ]);

            return SymfonyCommand::FAILURE;
        }

        // Build [sourceId => canonicalId] map by walking $rawPlan.
        /** @var array<int, int> $sourceToCanonical */
        $sourceToCanonical = [];
        foreach ($rawPlan as $entry) {
            $canonicalId = (int) $entry['canonical']['id'];
            foreach ($entry['sources'] as $src) {
                $sourceToCanonical[(int) $src['id']] = $canonicalId;
            }
        }

        // ── 3. Filter by --source-ids if set ────────────────────────────────
        $sourceNotADuplicate = 0;
        if ($explicitSourceIds !== []) {
            /** @var array<int, int> $filtered */
            $filtered = [];
            foreach ($explicitSourceIds as $sid) {
                if (! isset($sourceToCanonical[$sid])) {
                    $sourceNotADuplicate++;
                    $this->warn("  source_not_a_duplicate source={$sid} — skipping (not in any duplicate group)");

                    continue;
                }
                $filtered[$sid] = $sourceToCanonical[$sid];
            }
            $sourceToCanonical = $filtered;
        }

        // ── 4. Initialise counters + sample buffer ──────────────────────────
        $groupsProcessed = 0;
        $productsScanned = 0;
        $productsRetagged = 0;
        $wouldRetag = 0;
        $alreadyCanonical = 0;
        $errors = 0;
        $noProductsOnWoo = 0;
        $processedCount = 0; // global cross-source counter for --limit
        /** @var array<int, array{int, int, string, int, string, string}> $sample */
        $sample = []; // [wooId, sourceId, sku, canonicalId, currentBrandsCsv, newBrandsCsv]

        /** @var array<int, array{source_id:int, canonical_id:int, products_scanned:int, products_retagged:int, already_canonical:int, errors:int}> $perSource */
        $perSource = [];

        // ── 5. Per-source product loop ──────────────────────────────────────
        $hitLimit = false;
        foreach ($sourceToCanonical as $sourceId => $canonicalId) {
            if ($hitLimit) {
                break;
            }
            $groupsProcessed++;
            $perSource[$sourceId] = [
                'source_id' => $sourceId,
                'canonical_id' => $canonicalId,
                'products_scanned' => 0,
                'products_retagged' => 0,
                'already_canonical' => 0,
                'errors' => 0,
            ];

            $page = 1;
            while (true) {
                try {
                    $response = $this->woo->get('products', [
                        'brand' => $sourceId,
                        'per_page' => self::PRODUCTS_PER_PAGE,
                        'page' => $page,
                    ]);
                } catch (\Throwable $e) {
                    // 404 detection — term deleted between discovery and now.
                    // Mirrors DedupeBrandsCommand 404 detection (lines 319-321).
                    $is404 = ((int) $e->getCode() === 404)
                        || (str_contains($e->getMessage(), 'term does not exist'))
                        || (str_contains($e->getMessage(), 'rest_term_invalid'));

                    if ($is404) {
                        $noProductsOnWoo++;
                        $this->line("  no_products_on_woo source={$sourceId} (term deleted between discovery and now)");
                        $this->auditor->record('brands.retag_no_products_on_woo', [
                            'source_id' => $sourceId,
                            'canonical_id' => $canonicalId,
                        ]);
                    } else {
                        $errors++;
                        $perSource[$sourceId]['errors']++;
                        $this->warn("  ! products GET source={$sourceId} page={$page} failed: {$e->getMessage()}");
                        $this->auditor->record('brands.retag_pagination_failed', [
                            'source_id' => $sourceId,
                            'canonical_id' => $canonicalId,
                            'page' => $page,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    break; // stop paginating this source
                }

                if (! is_array($response) || $response === []) {
                    break;
                }

                foreach ($response as $row) {
                    // stdClass→array cast — commit 9581de8 pattern.
                    if (! is_array($row)) {
                        $row = json_decode((string) json_encode($row), true);
                    }
                    if (! is_array($row)) {
                        continue;
                    }

                    $wooProductId = (int) ($row['id'] ?? 0);
                    if ($wooProductId <= 0) {
                        continue;
                    }
                    $sku = (string) ($row['sku'] ?? '');

                    // Extract current brand IDs from the brands[] array.
                    $currentBrandIds = [];
                    $currentBrandNames = [];
                    foreach (($row['brands'] ?? []) as $b) {
                        if (! is_array($b)) {
                            $b = json_decode((string) json_encode($b), true);
                        }
                        if (! is_array($b)) {
                            continue;
                        }
                        $bid = (int) ($b['id'] ?? 0);
                        if ($bid > 0) {
                            $currentBrandIds[] = $bid;
                            $currentBrandNames[$bid] = (string) ($b['name'] ?? '');
                        }
                    }

                    // Compute new brand IDs: (current MINUS source) UNION canonical.
                    $newBrandIds = array_values(array_unique(array_merge(
                        array_diff($currentBrandIds, [$sourceId]),
                        [$canonicalId],
                    )));
                    sort($newBrandIds);

                    $currentSorted = $currentBrandIds;
                    sort($currentSorted);
                    $currentSorted = array_values(array_unique($currentSorted));

                    $productsScanned++;
                    $perSource[$sourceId]['products_scanned']++;
                    $processedCount++;

                    // Already canonical? Tag set unchanged → no PUT.
                    if ($newBrandIds === $currentSorted) {
                        $alreadyCanonical++;
                        $perSource[$sourceId]['already_canonical']++;
                        $this->line("  already_canonical woo={$wooProductId} sku={$sku}");

                        if ($limit > 0 && $processedCount >= $limit) {
                            $hitLimit = true;
                            break;
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $wouldRetag++;
                        $this->line("  would_retag woo={$wooProductId} sku={$sku} from={$sourceId} to={$canonicalId}");
                        if (count($sample) < 20) {
                            $sample[] = [
                                $wooProductId,
                                $sourceId,
                                $sku,
                                $canonicalId,
                                implode(',', $currentBrandIds),
                                implode(',', $newBrandIds),
                            ];
                        }

                        if ($limit > 0 && $processedCount >= $limit) {
                            $hitLimit = true;
                            break;
                        }
                        continue;
                    }

                    // LIVE — PUT the new brands array.
                    try {
                        $this->woo->put("products/{$wooProductId}", [
                            'brands' => array_map(
                                static fn (int $id): array => ['id' => $id],
                                $newBrandIds,
                            ),
                        ]);
                    } catch (\Throwable $e) {
                        $errors++;
                        $perSource[$sourceId]['errors']++;
                        $this->warn("  ! PUT woo={$wooProductId} sku={$sku}: {$e->getMessage()}");
                        $this->auditor->record('brands.retag_failed', [
                            'product_id' => $wooProductId,
                            'sku' => $sku,
                            'from_brand_id' => $sourceId,
                            'to_brand_id' => $canonicalId,
                            'error' => $e->getMessage(),
                        ]);

                        if ($limit > 0 && $processedCount >= $limit) {
                            $hitLimit = true;
                            break;
                        }
                        continue;
                    }

                    $productsRetagged++;
                    $perSource[$sourceId]['products_retagged']++;
                    $this->line("  pushed woo={$wooProductId} sku={$sku} from={$sourceId} to={$canonicalId}");
                    $this->auditor->record('brands.product_retagged', [
                        'product_id' => $wooProductId,
                        'sku' => $sku,
                        'from_brand_id' => $sourceId,
                        'to_brand_id' => $canonicalId,
                        'new_brand_ids' => $newBrandIds,
                    ]);

                    // 200ms throttle between successful live PUTs only — skipped
                    // on errors / already_canonical / dry-run.
                    usleep(self::WOO_PUT_THROTTLE_USEC);

                    if ($limit > 0 && $processedCount >= $limit) {
                        $hitLimit = true;
                        break;
                    }
                }

                if ($hitLimit) {
                    break;
                }

                // Per-page-short ⇒ no more pages for this source.
                if (count($response) < self::PRODUCTS_PER_PAGE) {
                    break;
                }
                $page++;
            }
        }

        // ── 6. Final counter table ───────────────────────────────────────────
        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['groups_processed', $groupsProcessed],
                ['products_scanned', $productsScanned],
                [$dryRun ? 'would_retag' : 'products_retagged', $dryRun ? $wouldRetag : $productsRetagged],
                ['already_canonical', $alreadyCanonical],
                ['errors', $errors],
                ['no_products_on_woo', $noProductsOnWoo],
                ['source_not_a_duplicate', $sourceNotADuplicate],
            ],
        );

        if ($perSource !== []) {
            $this->newLine();
            $this->info('Per-source breakdown:');
            $rows = [];
            foreach ($perSource as $entry) {
                $rows[] = [
                    (string) $entry['source_id'],
                    (string) $entry['canonical_id'],
                    (string) $entry['products_scanned'],
                    (string) $entry['products_retagged'],
                    (string) $entry['already_canonical'],
                    (string) $entry['errors'],
                ];
            }
            $this->table(
                ['Source id', 'Canonical id', 'Products scanned', 'Products retagged', 'Already canonical', 'Errors'],
                $rows,
            );
        }

        if ($dryRun && $sample !== []) {
            $this->newLine();
            $this->info('Dry-run sample (first 20 would_retag decisions):');
            $this->table(
                ['Woo product id', 'Source id', 'SKU', 'Canonical id', 'Current brands', 'New brands'],
                array_map(static fn (array $r): array => [
                    (string) $r[0],
                    (string) $r[1],
                    $r[2],
                    (string) $r[3],
                    $r[4],
                    $r[5],
                ], $sample),
            );
        }

        // Per-product failures are reported via counter table, NOT a non-zero
        // exit. Matches PushVisibilityToWooCommand + DedupeBrandsCommand
        // precedent — operator decides next action from the summary.
        return SymfonyCommand::SUCCESS;
    }
}
