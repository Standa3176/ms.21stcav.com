<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Services\BrandDuplicateFinder;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260613-dir — brands:dedupe.
 *
 * Finds case-insensitive duplicate Woo `product_brand` terms (legacy WC import
 * residue: "Poly" vs "poly", " Logitech " vs "Logitech", etc.) and merges MS
 * `products.brand_id` from non-canonical → canonical. Optionally deletes the
 * now-empty Woo source terms via gated `--delete-empty-woo-terms`.
 *
 * Why now: 260611-sr7 backfilled 3,106 brand_ids via name-first-word heuristics.
 * Years of legacy WC imports left the Woo `product_brand` taxonomy with case-
 * mismatch dupes and trailing-whitespace dupes. After the backfill, MS products
 * point at a mix of canonical AND duplicate Woo term ids — every duplicate that
 * owns a product is a fragmented brand surface on the storefront (separate
 * /product-brand/{slug}/ landing pages, split product counts, split SEO juice).
 *
 * **Scope:** ONLY case-insensitive trimmed-name matches (`strtolower(trim($name))`).
 * Fuzzy / alias matching (e.g. "HP" vs "Hewlett-Packard") is OUT OF SCOPE — aliases
 * require operator judgement and are handled via the existing Filament brand-mapping
 * UI, NOT this command.
 *
 * **Canonical selection:** delegated to `BrandDuplicateFinder` — the clean
 * SLUG-RANK survivor (the tidy slug is the intended canonical; sources are
 * MOVED onto it, never deleted out from under their products). This replaced the
 * old "highest Woo `count` DESC" heuristic after the 2026-06-13 incident.
 * Deterministic — re-running produces the same canonical pick, not a flip-flop.
 *
 * **MANDATORY operator order (do NOT skip step 1):**
 *   1. `php artisan brands:retag-products-on-woo` — moves the actual Woo
 *      `product_brand` product membership from each source term onto the
 *      canonical term. THIS is what empties the source terms on Woo.
 *   2. `php artisan brands:dedupe --delete-empty-woo-terms` — deletes the
 *      now-empty source terms.
 *   Running step 2 WITHOUT step 1 would try to delete terms that still hold
 *   live Woo products. The 260726-deg emptiness guard (see Phase B below)
 *   HARD-BLOCKS that: any term the live Woo `products?brand=<id>` read still
 *   reports as non-empty is skipped, never deleted.
 *
 * **Two-phase safety:**
 *   - Phase A (LOCAL reassignment) is SAFE — products always have a valid
 *     brand_id (canonical exists; we move them from source to canonical inside a
 *     DB::transaction per source). NOTE: this only touches the LOCAL
 *     products.brand_id view, which is single-valued and blind to Woo's
 *     many-to-many `product_brand` membership.
 *   - Phase B (Woo DELETE) is RISKY — other plugins (Yoast SEO schema, Google
 *     Listings & Ads feed, Flatsome theme overrides) may reference the deleted
 *     term ids, AND (260726-bwa) a source term with zero LOCAL products can
 *     still own hundreds of live Woo products. Gated behind
 *     `--delete-empty-woo-terms` (default OFF) AND, since 260726-deg, guarded by
 *     a live per-term emptiness read: the DELETE is impossible to fire against a
 *     term Woo still reports as non-empty (and fail-safe-skips on any check
 *     uncertainty). Operator runs `brands:retag-products-on-woo` first, then
 *     Phase A alone, spot-checks storefront, then opts into the delete.
 *
 * **Idempotence:**
 *   - Re-running on already-deduped state: `groups_found=0` fast path; no writes,
 *     no audit rows.
 *   - Re-running `--delete-empty-woo-terms` on already-deleted terms: 404 from
 *     Woo increments `already_deleted` counter (NOT `errors`) and writes the
 *     `brands.dedupe_woo_term_already_deleted` audit row — desired end-state,
 *     not an alarm.
 *
 * **Drift-prevention:** ALL Woo writes via `$this->woo` (WooClient). Direct Http:: /
 * Guzzle / new AutomatticClient() in this command would bypass IntegrationLogger
 * audit trail + correlation_id threading. If a future quick task adds variation
 * brand dedup or a 4th brand surface, EXTEND this command — do not re-implement
 * the pagination + grouping + canonical-selection elsewhere.
 *
 * **Untouched:** BackfillProductBrandFromNameCommand / BackfillMerchantFeedCommand /
 * BackfillCategoryFromWooCommand / TaxonomyResolver / WooClient.
 *
 *   php artisan brands:dedupe --dry-run
 *   php artisan brands:dedupe
 *   php artisan brands:dedupe --delete-empty-woo-terms
 */
// Not `final` so the Pest feature test can swap WooClient + Auditor via the
// container without subclassing the command itself (mirrors PushVisibilityToWooCommand
// + BackfillProductBrandFromNameCommand pattern).
class DedupeBrandsCommand extends BaseCommand
{
    /**
     * 200ms pacing between live Woo DELETEs. Mirrors PushVisibilityToWooCommand
     * line 167 + BackfillCategoryFromWooCommand cadence. WooClient's built-in
     * 429 backoff is the backstop; this throttle keeps bursty bulk operations
     * polite by default.
     */
    private const WOO_DELETE_THROTTLE_USEC = 200_000;

    /**
     * 260726-deg — emptiness-guard verdicts. The Phase-B DELETE is only allowed
     * when the live Woo `products?brand=<id>` read PROVES the term empty.
     */
    private const TERM_EMPTY = 'empty';

    private const TERM_HAS_PRODUCTS = 'has_products';

    private const TERM_CHECK_FAILED = 'check_failed';

    protected $signature = 'brands:dedupe
        {--dry-run : Print plan without writes}
        {--delete-empty-woo-terms : After reassignment, DELETE the duplicate Woo terms via WooClient::delete (default off — gated)}';

    protected $description = 'Find case-insensitive duplicate Woo product_brand terms and merge MS products.brand_id non-canonical → canonical (260613-dir).';

    // 260613-f2r — pagination + grouping + canonical-pick moved to
    // BrandDuplicateFinder so the new RetagProductsOnWooCommand can share
    // discovery without duplicating the loop. Auditor stays here (only this
    // command writes audit rows during dedupe); $woo stays here for the
    // Phase-B DELETE call.
    public function __construct(
        private readonly WooClient $woo,
        private readonly Auditor $auditor,
        private readonly BrandDuplicateFinder $finder,
    ) {
        parent::__construct();
    }

    // Drift-prevention: ALL Woo writes via $this->woo (WooClient). Direct Http:: /
    // Guzzle / new AutomatticClient() in this command would bypass IntegrationLogger
    // audit trail + correlation_id threading. If a future quick task adds variation
    // brand dedup or a 4th brand surface, EXTEND this command — do not re-implement
    // the pagination + grouping + canonical-selection elsewhere.
    protected function perform(): int
    {
        // ── 1. Parse options ─────────────────────────────────────────────────
        $dryRun = (bool) $this->option('dry-run');
        $deleteEmpty = (bool) $this->option('delete-empty-woo-terms');

        $this->info(
            ($dryRun ? '[dry-run] ' : '[LIVE] ')
            .'brands:dedupe — delete_empty_woo_terms='
            .($deleteEmpty ? 'true' : 'false')
        );

        // ── 2. Discover duplicates via BrandDuplicateFinder (260613-f2r) ─────
        // Pagination + grouping + canonical-pick lifted to the service so
        // 260613-f2r's RetagProductsOnWooCommand can share the same logic.
        // Pagination failures bubble here and are audited verbatim (same
        // shape as before the extract — `brands.dedupe_pagination_failed`).
        try {
            $rawPlan = $this->finder->discover();
        } catch (\Throwable $e) {
            $this->warn("  ! brands discovery failed: {$e->getMessage()}");
            $this->auditor->record('brands.dedupe_pagination_failed', [
                'page' => 0, // service doesn't expose the failing page number; 0 is the sentinel
                'error' => $e->getMessage(),
            ]);

            return SymfonyCommand::FAILURE;
        }

        $groupsFound = count($rawPlan);

        // ── 3. Annotate plan with planned_affected per source (DB count) ─────
        // planned_affected stays in this command — the per-source product
        // count is DedupeBrands-specific (RetagProducts doesn't need it).
        /** @var array<string, array{canonical:array{id:int,name:string,count:int}, sources:array<int,array{id:int,name:string,count:int}>, planned_affected:array<int,int>}> $plan */
        $plan = [];
        $wouldReassignProducts = 0;
        foreach ($rawPlan as $key => $entry) {
            $plannedAffected = [];
            foreach ($entry['sources'] as $src) {
                $cnt = (int) DB::table('products')->where('brand_id', $src['id'])->count();
                $plannedAffected[$src['id']] = $cnt;
                $wouldReassignProducts += $cnt;
            }

            $plan[$key] = [
                'canonical' => $entry['canonical'],
                'sources' => $entry['sources'],
                'planned_affected' => $plannedAffected,
            ];
        }

        // ── 5. Dry-run branch ────────────────────────────────────────────────
        if ($dryRun) {
            $this->renderPlanSections($plan, $deleteEmpty);

            $this->newLine();
            $this->table(
                ['Outcome', 'Count'],
                [
                    ['groups_found', $groupsFound],
                    ['would_merge_sources', $this->countSources($plan)],
                    ['would_reassign_products', $wouldReassignProducts],
                    ['would_delete_woo_terms', $deleteEmpty ? $this->countSources($plan) : 0],
                ],
            );

            return SymfonyCommand::SUCCESS;
        }

        // ── 6. Live branch — Phase A: reassignments first ────────────────────
        $sourcesMerged = 0;
        $productsReassigned = 0;
        $wooTermsDeleted = 0;
        $alreadyDeleted = 0;
        $skippedNonEmpty = 0;
        $errors = 0;
        /** @var array<string, array{canonical:array{id:int,name:string,count:int}, sources_merged:array<int,int>, products_reassigned:int, woo_terms_deleted:array<int,int>}> $perGroupSummary */
        $perGroupSummary = [];

        foreach ($plan as $key => $entry) {
            $canonical = $entry['canonical'];
            $perGroupSummary[$key] = [
                'canonical' => $canonical,
                'sources_merged' => [],
                'products_reassigned' => 0,
                'woo_terms_deleted' => [],
            ];

            foreach ($entry['sources'] as $source) {
                $sourceId = $source['id'];
                $sourceName = $source['name'];
                $canonicalId = $canonical['id'];
                $canonicalName = $canonical['name'];

                try {
                    $affected = DB::transaction(function () use ($sourceId, $canonicalId): int {
                        return DB::table('products')
                            ->where('brand_id', $sourceId)
                            ->update([
                                'brand_id' => $canonicalId,
                                'updated_at' => now(),
                            ]);
                    });
                } catch (\Throwable $e) {
                    $errors++;
                    $this->warn("  ! reassign failed from={$sourceId} to={$canonicalId}: {$e->getMessage()}");
                    $this->auditor->record('brands.dedupe_reassign_failed', [
                        'from_id' => $sourceId,
                        'to_id' => $canonicalId,
                        'from_name' => $sourceName,
                        'to_name' => $canonicalName,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $sourcesMerged++;
                $productsReassigned += (int) $affected;
                $perGroupSummary[$key]['sources_merged'][] = $sourceId;
                $perGroupSummary[$key]['products_reassigned'] += (int) $affected;

                $this->auditor->record('brands.dedupe_reassigned', [
                    'from_id' => $sourceId,
                    'to_id' => $canonicalId,
                    'from_name' => $sourceName,
                    'to_name' => $canonicalName,
                    'products_affected' => (int) $affected,
                ]);
            }
        }

        // ── 7. Live branch — Phase B: Woo deletes (only if --delete-empty-woo-terms) ──
        // CRITICAL: runs AFTER all reassignments complete. Two-phase ordering is the
        // whole reason `--delete-empty-woo-terms` is gated — if we deleted a source
        // term BEFORE reassigning its products, those products would lose their
        // brand link entirely.
        if ($deleteEmpty) {
            foreach ($plan as $key => $entry) {
                $canonical = $entry['canonical'];
                $canonicalId = $canonical['id'];

                foreach ($entry['sources'] as $source) {
                    $sourceId = $source['id'];
                    $sourceName = $source['name'];

                    // ── SAFETY GUARD (260726-deg) ────────────────────────────
                    // Phase A only reassigns the LOCAL products.brand_id view. Woo
                    // `product_brand` is many-to-many, so a source term with zero
                    // local products can still hold hundreds of live Woo products
                    // (260726-bwa: yealink 285, logitech 176, samsung 163, …). The
                    // force-delete would strip the brand off every one of them. So
                    // before deleting, we ask Woo directly whether the term is empty.
                    $emptiness = $this->wooTermEmptinessStatus($sourceId);

                    if ($emptiness === self::TERM_HAS_PRODUCTS) {
                        // Proven non-empty — refuse to delete.
                        $skippedNonEmpty++;
                        $this->warn("  ! SKIP delete source={$sourceId} ({$sourceName}) — Woo term still holds products");
                        $this->auditor->record('brands.dedupe_woo_term_not_empty_skipped', [
                            'source_id' => $sourceId,
                            'source_name' => $sourceName,
                            'canonical_id' => $canonicalId,
                        ]);
                    } elseif ($emptiness === self::TERM_CHECK_FAILED) {
                        // Fail-safe: the emptiness read errored (non-404), so we
                        // CANNOT prove the term empty. Treat as non-empty and skip —
                        // never delete on uncertainty.
                        $skippedNonEmpty++;
                        $this->warn("  ! SKIP delete source={$sourceId} ({$sourceName}) — emptiness check failed, cannot prove empty (fail-safe)");
                        $this->auditor->record('brands.dedupe_woo_emptiness_check_failed', [
                            'source_id' => $sourceId,
                            'source_name' => $sourceName,
                            'canonical_id' => $canonicalId,
                        ]);
                    } else {
                        // Proven empty (read returned [] or 404 = term already gone).
                        // Run the existing delete — a 404 here lands on already_deleted,
                        // the desired idempotent end-state.
                        try {
                            $this->woo->delete("products/brands/{$sourceId}", ['force' => true]);
                            $wooTermsDeleted++;
                            $perGroupSummary[$key]['woo_terms_deleted'][] = $sourceId;
                            $this->auditor->record('brands.dedupe_woo_term_deleted', [
                                'source_id' => $sourceId,
                                'source_name' => $sourceName,
                                'canonical_id' => $canonicalId,
                            ]);
                        } catch (\Throwable $e) {
                            // 404 detection: WP REST returns 404 for terms that no longer
                            // exist. Same idiom shared with the emptiness guard via
                            // isMissingTermError().
                            if ($this->isMissingTermError($e)) {
                                $alreadyDeleted++;
                                $this->line("  already_deleted source={$sourceId}");
                                $this->auditor->record('brands.dedupe_woo_term_already_deleted', [
                                    'source_id' => $sourceId,
                                    'source_name' => $sourceName,
                                    'canonical_id' => $canonicalId,
                                ]);
                            } else {
                                $errors++;
                                $this->warn("  ! Woo delete failed source={$sourceId}: {$e->getMessage()}");
                                $this->auditor->record('brands.dedupe_woo_term_error', [
                                    'source_id' => $sourceId,
                                    'source_name' => $sourceName,
                                    'canonical_id' => $canonicalId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                    // Pacing applies whether we deleted, skipped, or errored — keeps
                    // the command polite even when Woo is returning 5xx in a tight
                    // loop. EXACTLY ONE throttle per source iteration.
                    usleep(self::WOO_DELETE_THROTTLE_USEC);
                }
            }
        }

        // ── 8. Per-group summary table — operator-visible breakdown ──────────
        if ($perGroupSummary !== []) {
            $this->newLine();
            $this->info('Per-group breakdown:');
            $rows = [];
            foreach ($perGroupSummary as $key => $summary) {
                $row = [
                    $key,
                    $summary['canonical']['id'].' ('.$summary['canonical']['name'].')',
                    implode(',', $summary['sources_merged']),
                    (string) $summary['products_reassigned'],
                ];
                if ($deleteEmpty) {
                    $row[] = implode(',', $summary['woo_terms_deleted']);
                }
                $rows[] = $row;
            }
            $headers = ['Group key', 'Canonical', 'Sources merged (ids)', 'Products reassigned'];
            if ($deleteEmpty) {
                $headers[] = 'Woo terms deleted';
            }
            $this->table($headers, $rows);
        }

        // ── 9. Final counter table ───────────────────────────────────────────
        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['groups_found', $groupsFound],
                ['sources_merged', $sourcesMerged],
                ['products_reassigned', $productsReassigned],
                ['woo_terms_deleted', $wooTermsDeleted],
                ['already_deleted', $alreadyDeleted],
                ['skipped_non_empty', $skippedNonEmpty],
                ['errors', $errors],
            ],
        );

        // Per-source errors are reported via counter table, NOT a non-zero exit.
        // Matches PushVisibilityToWooCommand precedent — operator decides next action.
        return SymfonyCommand::SUCCESS;
    }

    /**
     * Render the 3-section dry-run plan (groups / reassignments / Woo deletes).
     *
     * @param  array<string, array{canonical:array{id:int,name:string,count:int}, sources:array<int,array{id:int,name:string,count:int}>, planned_affected:array<int,int>}>  $plan
     */
    private function renderPlanSections(array $plan, bool $deleteEmpty): void
    {
        $this->newLine();
        $this->info('Section 1 — Duplicate groups:');
        $rows = [];
        foreach ($plan as $key => $entry) {
            $canonical = $entry['canonical'];
            $sourceIds = implode(',', array_map(static fn (array $s): int => $s['id'], $entry['sources']));
            $rows[] = [
                $key,
                (string) $canonical['id'],
                $canonical['name'],
                (string) $canonical['count'],
                $sourceIds,
            ];
        }
        $this->table(['Group key', 'Canonical id', 'Canonical name', 'Canonical count', 'Source ids'], $rows);

        $this->newLine();
        $this->info('Section 2 — Reassignment plan:');
        $rows = [];
        foreach ($plan as $entry) {
            $canonical = $entry['canonical'];
            foreach ($entry['sources'] as $source) {
                $rows[] = [
                    (string) $source['id'],
                    $source['name'],
                    (string) $source['count'],
                    (string) $canonical['id'],
                    (string) ($entry['planned_affected'][$source['id']] ?? 0),
                ];
            }
        }
        $this->table(['Source id', 'Source name', 'Source count', 'Canonical id', 'Products affected'], $rows);

        if ($deleteEmpty) {
            $this->newLine();
            $this->info('Section 3 — Woo term deletes (--delete-empty-woo-terms):');
            // 260726-deg — run the SAME live emptiness guard the live path uses so
            // the operator sees the TRUTH per term (skip vs delete). READ-ONLY: GET
            // only, zero deletes — dry-run never mutates Woo.
            $rows = [];
            foreach ($plan as $entry) {
                foreach ($entry['sources'] as $source) {
                    $status = $this->wooTermEmptinessStatus($source['id']);
                    [$liveProducts, $willDelete] = match ($status) {
                        self::TERM_HAS_PRODUCTS => ['≥1 (live)', 'SKIP — term still holds products'],
                        self::TERM_CHECK_FAILED => ['? (check failed)', 'SKIP — cannot prove empty (fail-safe)'],
                        default => ['0', 'yes (empty, force=true)'],
                    };
                    $rows[] = [
                        (string) $source['id'],
                        $source['name'],
                        $liveProducts,
                        $willDelete,
                    ];
                }
            }
            $this->table(['Source id', 'Source name', 'Live Woo products', 'Will delete?'], $rows);
        }
    }

    /**
     * @param  array<string, array{sources:array<int,array{id:int,name:string,count:int}>}>  $plan
     */
    private function countSources(array $plan): int
    {
        $total = 0;
        foreach ($plan as $entry) {
            $total += count($entry['sources']);
        }

        return $total;
    }

    /**
     * 260726-deg — ask Woo directly whether a `product_brand` term is empty
     * before letting Phase B delete it.
     *
     * The single source of truth is Woo's own membership, NOT the local
     * products.brand_id view (which is single-valued and blind to the Woo
     * many-to-many taxonomy — the whole reason the 260726-bwa incident was
     * possible). READ-ONLY: one GET, zero writes.
     *
     * Returns:
     *   - self::TERM_HAS_PRODUCTS — read returned ≥1 product; DO NOT delete.
     *   - self::TERM_CHECK_FAILED — read threw a NON-404 error; we cannot prove
     *     the term empty, so fail-safe (treat as non-empty, DO NOT delete).
     *   - self::TERM_EMPTY        — read returned [] OR 404 (term already gone);
     *     deletion is safe (a 404 on the subsequent delete → already_deleted).
     */
    private function wooTermEmptinessStatus(int $sourceId): string
    {
        try {
            $products = $this->woo->get('products', [
                'brand' => $sourceId,
                'per_page' => 1,
                'status' => 'any',
            ]);
        } catch (\Throwable $e) {
            // 404 on the emptiness check ⇒ the term no longer exists ⇒ nothing to
            // protect ⇒ let the existing delete run and land on already_deleted.
            if ($this->isMissingTermError($e)) {
                return self::TERM_EMPTY;
            }

            // Any other failure (5xx, network, WAF) ⇒ we could not prove the term
            // empty. NEVER delete on uncertainty — fail safe.
            return self::TERM_CHECK_FAILED;
        }

        return $products === [] ? self::TERM_EMPTY : self::TERM_HAS_PRODUCTS;
    }

    /**
     * 260726-deg — shared 404 / missing-term detection idiom (previously inlined
     * only in the Phase-B delete catch). WP REST returns 404 for terms that no
     * longer exist; match on code OR message-string, defensive across SDK
     * wrappers (HttpClientException carries code via getCode(), and
     * rest_term_invalid is the WP REST error key).
     */
    private function isMissingTermError(\Throwable $e): bool
    {
        return ((int) $e->getCode() === 404)
            || str_contains($e->getMessage(), 'term does not exist')
            || str_contains($e->getMessage(), 'rest_term_invalid');
    }
}
