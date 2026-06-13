<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 * **Canonical selection:** highest Woo `count` DESC, tie-break by lowest term id ASC.
 * Deterministic — re-running produces the same canonical pick, not a flip-flop.
 *
 * **Two-phase safety:**
 *   - Phase A (reassignment) is SAFE — products always have a valid brand_id
 *     (canonical exists; we move them from source to canonical inside a
 *     DB::transaction per source).
 *   - Phase B (Woo DELETE) is RISKY — other plugins (Yoast SEO schema, Google
 *     Listings & Ads feed, Flatsome theme overrides) may reference the deleted
 *     term ids. Gated behind `--delete-empty-woo-terms` (default OFF). Operator
 *     runs Phase A alone first, spot-checks storefront, then opts in.
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
     * Woo REST per-page cap. Grep-discoverable for future tuning.
     */
    private const BRANDS_PER_PAGE = 100;

    /**
     * 200ms pacing between live Woo DELETEs. Mirrors PushVisibilityToWooCommand
     * line 167 + BackfillCategoryFromWooCommand cadence. WooClient's built-in
     * 429 backoff is the backstop; this throttle keeps bursty bulk operations
     * polite by default.
     */
    private const WOO_DELETE_THROTTLE_USEC = 200_000;

    protected $signature = 'brands:dedupe
        {--dry-run : Print plan without writes}
        {--delete-empty-woo-terms : After reassignment, DELETE the duplicate Woo terms via WooClient::delete (default off — gated)}';

    protected $description = 'Find case-insensitive duplicate Woo product_brand terms and merge MS products.brand_id non-canonical → canonical (260613-dir).';

    public function __construct(
        private readonly WooClient $woo,
        private readonly Auditor $auditor,
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

        // ── 2. Page through Woo brands ───────────────────────────────────────
        /** @var array<int, array{id:int,name:string,count:int}> $brands */
        $brands = [];
        $page = 1;
        while (true) {
            try {
                $response = $this->woo->get('products/brands', [
                    'per_page' => self::BRANDS_PER_PAGE,
                    'page' => $page,
                ]);
            } catch (\Throwable $e) {
                $this->warn("  ! brands GET page={$page} failed: {$e->getMessage()}");
                $this->auditor->record('brands.dedupe_pagination_failed', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                return SymfonyCommand::FAILURE;
            }

            if (! is_array($response) || $response === []) {
                break;
            }

            foreach ($response as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $name = (string) ($row['name'] ?? '');
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $brands[] = [
                    'id' => $id,
                    'name' => $name,
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }

            // Defensive — if Woo returned less than per_page, no more pages.
            if (count($response) < self::BRANDS_PER_PAGE) {
                break;
            }

            $page++;
        }

        // ── 3. Group by lowercased + trimmed name ────────────────────────────
        /** @var array<string, array<int, array{id:int,name:string,count:int}>> $allGroups */
        $allGroups = [];
        foreach ($brands as $brand) {
            $key = strtolower(trim($brand['name']));
            $allGroups[$key][] = $brand;
        }

        /** @var array<string, array<int, array{id:int,name:string,count:int}>> $groups */
        $groups = array_filter($allGroups, static fn (array $g): bool => count($g) > 1);
        $groupsFound = count($groups);

        // ── 4. Determine canonical + merge sources per group ─────────────────
        /** @var array<string, array{canonical:array{id:int,name:string,count:int}, sources:array<int,array{id:int,name:string,count:int}>, planned_affected:array<int,int>}> $plan */
        $plan = [];
        $wouldReassignProducts = 0;
        foreach ($groups as $key => $group) {
            // Sort by count DESC, id ASC (inline closure — visible at the call site).
            usort($group, static function (array $a, array $b): int {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }

                return $a['id'] <=> $b['id'];
            });
            $canonical = $group[0];
            $sources = array_slice($group, 1);

            // Pre-count affected products per source — used for dry-run display + live counter.
            $plannedAffected = [];
            foreach ($sources as $src) {
                $cnt = (int) DB::table('products')->where('brand_id', $src['id'])->count();
                $plannedAffected[$src['id']] = $cnt;
                $wouldReassignProducts += $cnt;
            }

            $plan[$key] = [
                'canonical' => $canonical,
                'sources' => $sources,
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
                        // exist. Code or message-string match — defensive across SDK
                        // wrappers (HttpClientException carries code via getCode(),
                        // and rest_term_invalid is the WP REST error key).
                        $is404 = ((int) $e->getCode() === 404)
                            || (str_contains($e->getMessage(), 'term does not exist'))
                            || (str_contains($e->getMessage(), 'rest_term_invalid'));

                        if ($is404) {
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

                    // Pacing applies whether or not the call succeeded — keeps the
                    // command polite even when Woo is returning 5xx in a tight loop.
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
            $rows = [];
            foreach ($plan as $entry) {
                foreach ($entry['sources'] as $source) {
                    $rows[] = [
                        (string) $source['id'],
                        $source['name'],
                        'yes (force=true)',
                    ];
                }
            }
            $this->table(['Source id', 'Source name', 'Will delete via Woo?'], $rows);
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
}
