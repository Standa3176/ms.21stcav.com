<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\Sleeper;
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
 * **260726-slw — unattended `--slow` mode + discovery retry:** the shared box's
 * Woo REST endpoint intermittently returns non-JSON ("JSON ERROR: Syntax
 * error") on discovery reads, product GETs and PUTs — worse under sustained
 * write pressure — and WP's synchronous taxonomy recount degrades after ~120
 * rapid saves. Two additive hardenings:
 *   1. Discovery retry-with-backoff (BOTH modes): the single finder->discover()
 *      is retried up to --discovery-retries times with exponential backoff, so a
 *      flaky brands-list read no longer aborts the whole run.
 *   2. --slow self-pacing driver: discovers ONCE up front then grinds each
 *      source in --batch-size chunks, pausing --batch-pause seconds between
 *      batches (adaptive backoff on elevated error-rate / read failure), capped
 *      at --max-batches. A source is marked DRAINED only when a SUCCESSFUL read
 *      returns no new products — a transient read failure keeps it active for a
 *      later pass (never falsely finishes a source with products still on it).
 * All pauses route through an injectable {@see Sleeper} so tests never wait.
 *
 *   php artisan brands:retag-products-on-woo --dry-run
 *   php artisan brands:retag-products-on-woo --source-ids=12776,2904
 *   php artisan brands:retag-products-on-woo --limit=50
 *   php artisan brands:retag-products-on-woo --slow --source-ids=13430,13434,13432
 *   php artisan brands:retag-products-on-woo
 */
// Not `final` so the Pest feature test can swap WooClient + BrandDuplicateFinder
// + Auditor + Sleeper via the container without subclassing the command itself
// (mirrors DedupeBrandsCommand + PushVisibilityToWooCommand pattern).
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
     * polite by default. Routed through {@see Sleeper} (260726-slw) so tests
     * never actually wait — production behaviour is unchanged.
     */
    private const WOO_PUT_THROTTLE_USEC = 200_000;

    /**
     * Defensive backstop on page=1 drain iterations for a single batch. NOT an
     * expected exit path — see the 2026-06-13 (260613-ogv) incident note below.
     */
    private const MAX_PAGE_GUARD = 50;

    // ── Per-batch drain outcome statuses (260726-slw) ──────────────────────────
    // DRAINED / NO_PRODUCTS => source genuinely finished (safe to stop retrying).
    // MORE                  => batch-size cap or --limit hit; more may remain.
    // READ_ERROR            => the products GET itself errored → source is NOT
    //                          finished; keep it active for a later pass.
    private const STATUS_DRAINED = 'drained';

    private const STATUS_NO_PRODUCTS = 'no_products';

    private const STATUS_MORE = 'more';

    private const STATUS_READ_ERROR = 'read_error';

    protected $signature = 'brands:retag-products-on-woo
        {--dry-run : Print per-source plan + 20-row sample without writing to Woo}
        {--source-ids= : Comma-separated source brand ids to scope; default = auto-discover all duplicates}
        {--limit=0 : Global cap on total products processed across all sources (0=unbounded)}
        {--discovery-retries=4 : Attempts for the flaky brands-list discovery read before giving up (both modes)}
        {--discovery-backoff-ms=3000 : Base exponential backoff between discovery attempts (3s,6s,12s,...)}
        {--slow : Self-pacing unattended mode — discover once, grind in batches with adaptive pauses}
        {--batch-size=40 : (--slow) products processed per inner batch, per source}
        {--batch-pause=120 : (--slow) base seconds slept between batches to let WP taxonomy recount settle}
        {--max-pause=600 : (--slow) cap for adaptive backoff}
        {--max-batches=60 : (--slow) hard safety cap on total batches (runaway backstop)}';

    protected $description = 'Re-tag Woo products from duplicate (source) brand terms → canonical brand terms so brands:dedupe --delete-empty-woo-terms is safe (260613-f2r).';

    public function __construct(
        private readonly WooClient $woo,
        private readonly Auditor $auditor,
        private readonly BrandDuplicateFinder $finder,
        private readonly Sleeper $sleeper,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $slow = (bool) $this->option('slow');
        $discoveryRetries = max(1, (int) $this->option('discovery-retries'));
        $discoveryBackoffMs = max(0, (int) $this->option('discovery-backoff-ms'));
        $batchSize = max(1, (int) $this->option('batch-size'));
        $batchPause = max(0, (int) $this->option('batch-pause'));
        $maxPause = max($batchPause, (int) $this->option('max-pause'));
        $maxBatches = max(1, (int) $this->option('max-batches'));

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
            .($slow ? " [SLOW batch_size={$batchSize} batch_pause={$batchPause}s max_pause={$maxPause}s max_batches={$maxBatches}]" : '')
        );

        // ── 2. Discover duplicates via BrandDuplicateFinder (with retry) ─────
        // 260726-slw: the brands-list read is flaky. Retry-with-backoff up front
        // (BOTH modes); in --slow mode this is the ONLY discovery call — the
        // source→canonical map is reused for every batch (do NOT re-discover per
        // batch, which would re-hit the flaky endpoint N times).
        try {
            $rawPlan = $this->discoverWithRetry($discoveryRetries, $discoveryBackoffMs);
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

        // ── 4. Dispatch to the chosen driver ────────────────────────────────
        if ($slow) {
            return $this->runSlow(
                $sourceToCanonical,
                $dryRun,
                $limit,
                $batchSize,
                $batchPause,
                $maxPause,
                $maxBatches,
                $sourceNotADuplicate,
            );
        }

        return $this->runSinglePass($sourceToCanonical, $dryRun, $limit, $sourceNotADuplicate);
    }

    /**
     * Discover the source→canonical map, retrying the flaky brands-list read
     * with exponential backoff. Applies in BOTH modes (260726-slw).
     *
     * @return array<string, array{canonical:array{id:int,name:string,count:int,slug:string}, sources:array<int,array{id:int,name:string,count:int,slug:string}>}>
     *
     * @throws \Throwable when all attempts are exhausted — caller records the
     *                    FAILURE (mirrors the pre-260726 behaviour on a single
     *                    unrecoverable discovery error).
     */
    private function discoverWithRetry(int $retries, int $backoffMs): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->finder->discover();
            } catch (\Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }
                // Exponential: backoffMs * 2^(attempt-1) → 3s, 6s, 12s, 24s, ...
                $waitMs = $backoffMs * (2 ** ($attempt - 1));
                $this->warn("  ! discovery attempt {$attempt}/{$retries} failed: {$e->getMessage()} — retrying in {$waitMs}ms");
                $this->sleeper->micros($waitMs * 1000);
            }
        }
    }

    /**
     * Original single-pass behaviour (unchanged contract): one pass, drain each
     * source fully, print the counter + per-source + dry-run-sample tables.
     *
     * @param  array<int, int>  $sourceToCanonical
     */
    private function runSinglePass(array $sourceToCanonical, bool $dryRun, int $limit, int $sourceNotADuplicate): int
    {
        $groupsProcessed = 0;
        $productsScanned = 0;
        $productsRetagged = 0;
        $wouldRetag = 0;
        $alreadyCanonical = 0;
        $errors = 0;
        $noProductsOnWoo = 0;
        $processedCount = 0; // global cross-source counter for --limit
        /** @var array<int, array{int, int, string, int, string, string}> $sample */
        $sample = [];

        /** @var array<int, array{source_id:int, canonical_id:int, products_scanned:int, products_retagged:int, already_canonical:int, errors:int}> $perSource */
        $perSource = [];

        $hitLimit = false;
        foreach ($sourceToCanonical as $sourceId => $canonicalId) {
            if ($hitLimit) {
                break;
            }
            $groupsProcessed++;
            $perSource[$sourceId] = $this->newPerSourceRow($sourceId, $canonicalId);

            /** @var array<int, true> $processedIds */
            $processedIds = [];
            // batchCap=0 → unbounded → drain the source fully in this single pass.
            $result = $this->drainSourceBatch(
                $sourceId,
                $canonicalId,
                $dryRun,
                self::PRODUCTS_PER_PAGE,
                0,
                $limit,
                $processedIds,
                $sample,
                $processedCount,
                $hitLimit,
            );

            $productsScanned += $result['scanned'];
            $productsRetagged += $result['retagged'];
            $wouldRetag += $result['wouldRetag'];
            $alreadyCanonical += $result['already'];
            $errors += $result['errors'];
            $noProductsOnWoo += $result['noProducts'];
            $perSource[$sourceId]['products_scanned'] += $result['scanned'];
            $perSource[$sourceId]['products_retagged'] += $result['retagged'];
            $perSource[$sourceId]['already_canonical'] += $result['already'];
            $perSource[$sourceId]['errors'] += $result['errors'];
        }

        $this->printSummary(
            $dryRun,
            $groupsProcessed,
            $productsScanned,
            $productsRetagged,
            $wouldRetag,
            $alreadyCanonical,
            $errors,
            $noProductsOnWoo,
            $sourceNotADuplicate,
            $perSource,
            $sample,
        );

        return SymfonyCommand::SUCCESS;
    }

    /**
     * 260726-slw — self-pacing multi-batch driver. Discovers ONCE (already done
     * by the caller), then round-robins one batch per active source per pass,
     * pausing between batches with adaptive backoff, until every in-scope source
     * is drained OR --max-batches is reached.
     *
     * @param  array<int, int>  $sourceToCanonical
     */
    private function runSlow(
        array $sourceToCanonical,
        bool $dryRun,
        int $limit,
        int $batchSize,
        int $batchPause,
        int $maxPause,
        int $maxBatches,
        int $sourceNotADuplicate,
    ): int {
        $groupsProcessed = count($sourceToCanonical);
        $productsScanned = 0;
        $productsRetagged = 0;
        $wouldRetag = 0;
        $alreadyCanonical = 0;
        $errors = 0;
        $noProductsOnWoo = 0;
        $processedCount = 0;
        /** @var array<int, array{int, int, string, int, string, string}> $sample */
        $sample = [];

        /** @var array<int, array{source_id:int, canonical_id:int, products_scanned:int, products_retagged:int, already_canonical:int, errors:int}> $perSource */
        $perSource = [];
        /** @var array<int, array<int, true>> $processedIdsBySource — persisted across batches per source */
        $processedIdsBySource = [];
        /** @var array<int, string> $drained — sourceId => terminal reason (drained|no_products) */
        $drained = [];
        foreach ($sourceToCanonical as $sourceId => $canonicalId) {
            $perSource[$sourceId] = $this->newPerSourceRow($sourceId, $canonicalId);
            $processedIdsBySource[$sourceId] = [];
        }

        $pause = $batchPause;
        $batchesRun = 0;
        $hitLimit = false;
        $stoppedOnCap = false;

        while (true) {
            /** @var array<int, int> $activeSources */
            $activeSources = array_values(array_filter(
                array_keys($sourceToCanonical),
                static fn (int $sid): bool => ! isset($drained[$sid]),
            ));
            if ($activeSources === [] || $hitLimit) {
                break;
            }
            if ($batchesRun >= $maxBatches) {
                $stoppedOnCap = true;
                break;
            }

            foreach ($activeSources as $sourceId) {
                if ($batchesRun >= $maxBatches) {
                    $stoppedOnCap = true;
                    break;
                }
                $canonicalId = $sourceToCanonical[$sourceId];

                // Reuse the EXACT single-batch drain logic, bounded to batch-size
                // NEW products. per_page = batch-size keeps payloads small on the
                // flaky endpoint. processedIds is persisted per source across
                // batches so we never re-handle a product.
                $result = $this->drainSourceBatch(
                    $sourceId,
                    $canonicalId,
                    $dryRun,
                    $batchSize,
                    $batchSize,
                    $limit,
                    $processedIdsBySource[$sourceId],
                    $sample,
                    $processedCount,
                    $hitLimit,
                );
                $batchesRun++;

                $productsScanned += $result['scanned'];
                $productsRetagged += $result['retagged'];
                $wouldRetag += $result['wouldRetag'];
                $alreadyCanonical += $result['already'];
                $errors += $result['errors'];
                $noProductsOnWoo += $result['noProducts'];
                $perSource[$sourceId]['products_scanned'] += $result['scanned'];
                $perSource[$sourceId]['products_retagged'] += $result['retagged'];
                $perSource[$sourceId]['already_canonical'] += $result['already'];
                $perSource[$sourceId]['errors'] += $result['errors'];

                // ── Drained detection: distinguish empty-vs-error (THE subtle
                // correctness point). Only a SUCCESSFUL read that returns no new
                // products finishes a source. A read that errored (JSON/timeout)
                // leaves the source ACTIVE for a later pass.
                if ($result['status'] === self::STATUS_DRAINED || $result['status'] === self::STATUS_NO_PRODUCTS) {
                    $drained[$sourceId] = $result['status'];
                }

                // ── Adaptive backoff: error_rate = errors / max(1, scanned).
                $readFailed = $result['status'] === self::STATUS_READ_ERROR;
                $errorRate = $result['errors'] / max(1, $result['scanned']);
                if ($errorRate >= 0.5 || $readFailed) {
                    $pause = min($pause * 2, $maxPause);
                    $this->warn("  slow: source={$sourceId} error_rate=".number_format($errorRate, 2)
                        .($readFailed ? ' (read failed)' : '')." → backing off, next pause={$pause}s");
                } else {
                    $pause = $batchPause;
                }

                $this->line("  slow: batch {$batchesRun} source={$sourceId} scanned={$result['scanned']}"
                    ." retagged={$result['retagged']} errors={$result['errors']}"
                    ." status={$result['status']} next_pause={$pause}s");

                if ($hitLimit) {
                    break;
                }

                // Sleep the current pause between batches via the injectable
                // sleeper (tests record the durations; never actually wait).
                $this->sleeper->seconds($pause);

                if ($batchesRun >= $maxBatches) {
                    $stoppedOnCap = true;
                    break;
                }
            }
        }

        // ── Base summary (shared with single-pass) ───────────────────────────
        $this->printSummary(
            $dryRun,
            $groupsProcessed,
            $productsScanned,
            $productsRetagged,
            $wouldRetag,
            $alreadyCanonical,
            $errors,
            $noProductsOnWoo,
            $sourceNotADuplicate,
            $perSource,
            $sample,
        );

        // ── Slow-mode cumulative summary + per-source drained state ──────────
        $this->newLine();
        $this->info("Slow-mode summary: batches_run={$batchesRun} (max={$maxBatches}) current_pause={$pause}s");
        foreach ($sourceToCanonical as $sourceId => $canonicalId) {
            $state = $drained[$sourceId] ?? 'ACTIVE (not drained)';
            $this->line("  source={$sourceId} → {$state}");
        }

        if ($stoppedOnCap) {
            $this->warn("  ! stopped on --max-batches batch cap ({$maxBatches}) — NOT all sources drained; re-run to continue");
            $this->auditor->record('brands.retag_slow_batch_cap', [
                'max_batches' => $maxBatches,
                'batches_run' => $batchesRun,
            ]);
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Drain up to $batchCap NEW products from a single source (0 = unbounded).
     *
     * Reuses the 260613-ogv always-page-1 + processedIds drain contract verbatim.
     * A "batch" is one-or-more page=1 reads until the cap is hit, the read
     * returns no new products (drained), the read errors, --limit is reached, or
     * the safety guard trips.
     *
     * @param  array<int, true>  $processedIds  persisted dedup set (per source)
     * @param  array<int, array{int, int, string, int, string, string}>  $sample
     * @return array{status:string, scanned:int, retagged:int, already:int, errors:int, wouldRetag:int, noProducts:int}
     */
    private function drainSourceBatch(
        int $sourceId,
        int $canonicalId,
        bool $dryRun,
        int $perPage,
        int $batchCap,
        int $limit,
        array &$processedIds,
        array &$sample,
        int &$processedCount,
        bool &$hitLimit,
    ): array {
        $scanned = 0;
        $retagged = 0;
        $already = 0;
        $errors = 0;
        $wouldRetag = 0;
        $noProducts = 0;
        $newThisBatch = 0;
        $status = self::STATUS_DRAINED;
        $capReached = false;

        // 2026-06-13 INCIDENT (260613-ogv) — pagination must ALWAYS query page=1
        // because the filter set (`?brand=N`) shrinks under us as products are
        // retagged OFF the source brand. Safety break at 50 iterations is a
        // defensive backstop, NOT an expected exit path.
        $pageGuard = 0;
        while (true) {
            try {
                $response = $this->woo->get('products', [
                    'brand' => $sourceId,
                    'per_page' => $perPage,
                    'page' => 1,       // ALWAYS page 1 — the filter set shrinks as we retag products off this brand
                    'status' => 'any', // 260613-ogv — without this WC defaults to status=publish; pending/draft silently skipped
                ]);
            } catch (\Throwable $e) {
                // 404 detection — term deleted between discovery and now.
                $is404 = ((int) $e->getCode() === 404)
                    || (str_contains($e->getMessage(), 'term does not exist'))
                    || (str_contains($e->getMessage(), 'rest_term_invalid'));

                if ($is404) {
                    $noProducts = 1;
                    $status = self::STATUS_NO_PRODUCTS;
                    $this->line("  no_products_on_woo source={$sourceId} (term deleted between discovery and now)");
                    $this->auditor->record('brands.retag_no_products_on_woo', [
                        'source_id' => $sourceId,
                        'canonical_id' => $canonicalId,
                    ]);
                } else {
                    // 260726-slw — a read failure does NOT drain the source. The
                    // caller keeps it active so a later pass retries it.
                    $errors++;
                    $status = self::STATUS_READ_ERROR;
                    $this->warn("  ! products GET source={$sourceId} failed: {$e->getMessage()}");
                    $this->auditor->record('brands.retag_pagination_failed', [
                        'source_id' => $sourceId,
                        'canonical_id' => $canonicalId,
                        'error' => $e->getMessage(),
                    ]);
                }
                break; // stop paginating this source (this batch)
            }

            if (! is_array($response) || $response === []) {
                $status = self::STATUS_DRAINED;
                break;
            }

            // Keep only rows carrying a Woo product id we haven't handled yet.
            $newRows = array_filter($response, function ($row) use ($processedIds): bool {
                if (! is_array($row)) {
                    $row = json_decode((string) json_encode($row), true);
                }
                $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

                return $id > 0 && ! isset($processedIds[$id]);
            });
            if ($newRows === []) {
                $status = self::STATUS_DRAINED;
                break;
            }

            foreach ($newRows as $row) {
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
                // Mark handled so a repeated page=1 read drains to a clean break.
                $processedIds[$wooProductId] = true;
                $sku = (string) ($row['sku'] ?? '');

                // Extract current brand IDs from the brands[] array.
                $currentBrandIds = [];
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

                $scanned++;
                $newThisBatch++;
                $processedCount++;

                if ($newBrandIds === $currentSorted) {
                    // Already canonical — tag set unchanged → no PUT.
                    $already++;
                    $this->line("  already_canonical woo={$wooProductId} sku={$sku}");
                } elseif ($dryRun) {
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
                } else {
                    // LIVE — PUT the new brands array.
                    try {
                        $this->woo->put("products/{$wooProductId}", [
                            'brands' => array_map(
                                static fn (int $id): array => ['id' => $id],
                                $newBrandIds,
                            ),
                        ]);
                        $retagged++;
                        $this->line("  pushed woo={$wooProductId} sku={$sku} from={$sourceId} to={$canonicalId}");
                        $this->auditor->record('brands.product_retagged', [
                            'product_id' => $wooProductId,
                            'sku' => $sku,
                            'from_brand_id' => $sourceId,
                            'to_brand_id' => $canonicalId,
                            'new_brand_ids' => $newBrandIds,
                        ]);

                        // 200ms throttle between successful live PUTs only —
                        // skipped on errors / already_canonical / dry-run.
                        $this->sleeper->micros(self::WOO_PUT_THROTTLE_USEC);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->warn("  ! PUT woo={$wooProductId} sku={$sku}: {$e->getMessage()}");
                        $this->auditor->record('brands.retag_failed', [
                            'product_id' => $wooProductId,
                            'sku' => $sku,
                            'from_brand_id' => $sourceId,
                            'to_brand_id' => $canonicalId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($limit > 0 && $processedCount >= $limit) {
                    $hitLimit = true;
                    break;
                }
                if ($batchCap > 0 && $newThisBatch >= $batchCap) {
                    $status = self::STATUS_MORE;
                    $capReached = true;
                    break;
                }
            }

            if ($hitLimit) {
                $status = self::STATUS_MORE;
                break;
            }
            if ($capReached) {
                break; // status already STATUS_MORE
            }

            $pageGuard++;
            if ($pageGuard >= self::MAX_PAGE_GUARD) {
                $this->warn("  ! safety_break source={$sourceId}: ran ".self::MAX_PAGE_GUARD.' iterations of page=1, aborting source loop');
                $this->auditor->record('brands.retag_safety_break', [
                    'source_brand_id' => $sourceId,
                    'pages' => $pageGuard,
                ]);
                $status = self::STATUS_MORE;
                break;
            }
        }

        return [
            'status' => $status,
            'scanned' => $scanned,
            'retagged' => $retagged,
            'already' => $already,
            'errors' => $errors,
            'wouldRetag' => $wouldRetag,
            'noProducts' => $noProducts,
        ];
    }

    /**
     * @return array{source_id:int, canonical_id:int, products_scanned:int, products_retagged:int, already_canonical:int, errors:int}
     */
    private function newPerSourceRow(int $sourceId, int $canonicalId): array
    {
        return [
            'source_id' => $sourceId,
            'canonical_id' => $canonicalId,
            'products_scanned' => 0,
            'products_retagged' => 0,
            'already_canonical' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Print the shared counter table, per-source breakdown, and dry-run sample.
     *
     * @param  array<int, array{source_id:int, canonical_id:int, products_scanned:int, products_retagged:int, already_canonical:int, errors:int}>  $perSource
     * @param  array<int, array{int, int, string, int, string, string}>  $sample
     */
    private function printSummary(
        bool $dryRun,
        int $groupsProcessed,
        int $productsScanned,
        int $productsRetagged,
        int $wouldRetag,
        int $alreadyCanonical,
        int $errors,
        int $noProductsOnWoo,
        int $sourceNotADuplicate,
        array $perSource,
        array $sample,
    ): void {
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
    }
}
