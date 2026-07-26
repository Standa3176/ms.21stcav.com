<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Services\BrandDuplicateFinder;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260726-bwa — brands:audit-woo-membership (READ-ONLY).
 *
 * Reports the TRUE live Woo `product_brand` term membership per duplicate group
 * so the operator can pick the right canonical BEFORE any merge. Built after a
 * `brands:dedupe --dry-run` on prod (2026-07-26) exposed two blockers that make
 * a live run UNSAFE:
 *
 *   1. **Canonical picker is backwards for populated dups.** BrandDuplicateFinder
 *      ranks canonical by slug-shape (clean slug wins). For samsung / yealink /
 *      logitech the CLEAN-slug term is nearly empty while the numeric-suffix
 *      "source" term holds ~700 live products. `--delete-empty-woo-terms` would
 *      therefore delete the POPULATED term and orphan those products. (Note: the
 *      slug rule was itself the fix for the 2026-06-13 count-based incident — the
 *      real remedy here is Woo-membership-aware, NOT flipping back to count.)
 *   2. **Local blind spot.** `product_brand` is many-to-many on Woo; local
 *      `products.brand_id` is single-valued, so the dedupe saw ~10 reassignable
 *      products vs ~713 on the Woo terms. Some products carry BOTH duplicate
 *      terms.
 *
 * This command is the SAFE read: for each duplicate group it fetches (paginated,
 * bounded, read-only) the product ids on the canonical term and on each source
 * term, then reports the on-only / on-both / distinct maths, suggests the
 * canonical by MOST-PRODUCTS, and flags when the finder's slug pick disagrees.
 *
 * **HARD READ-ONLY contract:** the ONLY Woo call is `WooClient::get()`. There is
 * no put/post/patch/delete, no local `brand_id` write, no migration, no
 * WOO_WRITE_ENABLED change. The Pest suite stubs WooClient so any write throws
 * and asserts `writeCalls === []`.
 *
 * **Does NOT change** brands:dedupe / BrandDuplicateFinder / RetagProductsOnWoo.
 * The canonical fix + Woo-aware re-tag + gated delete are the SEPARATE supervised
 * follow-up.
 *
 * **Woo brand-term read pattern** (mirrors RetagProductsOnWooCommand):
 *   GET products?brand=<termId>&per_page=100&page=<n>&status=any
 * WC returns products carrying that `product_brand` term. `status=any` captures
 * pending/draft rows a publish-only default would skip. Pagination increments the
 * page (this is a pure read — the filter set does NOT shrink, so the always-page-1
 * drain trick used by the WRITE command does not apply here); it stops on a short
 * page, an empty page, or when the per-term cap is reached.
 *
 *   php artisan brands:audit-woo-membership
 *   php artisan brands:audit-woo-membership --csv=storage/app/brand-membership.csv
 *   php artisan brands:audit-woo-membership --per-term-cap=10000
 */
// Not `final` so the Pest feature test can swap WooClient + BrandDuplicateFinder
// via the container without subclassing the command (mirrors DedupeBrandsCommand).
class AuditWooBrandMembershipCommand extends BaseCommand
{
    /** Woo REST per-page cap. Mirrors the sibling brand commands. */
    private const PRODUCTS_PER_PAGE = 100;

    /**
     * Defensive page ceiling per term. At per_page=100 this bounds a single term
     * at 100k products regardless of the cap — a term will always drain long
     * before this. Purely a runaway-loop backstop.
     */
    private const MAX_PAGES_PER_TERM = 1000;

    protected $signature = 'brands:audit-woo-membership
        {--csv= : Write per-product rows (group, canonical_id, source_id, product_id, sku, name, on_canonical, on_source) to this path}
        {--per-term-cap=10000 : Max products fetched per Woo term before a logged cap note (bounds the read)}';

    protected $description = 'READ-ONLY: report the true live Woo product_brand term membership per duplicate group — the safe basis for a brand merge (260726-bwa).';

    public function __construct(
        private readonly WooClient $woo,
        private readonly Auditor $auditor,
        private readonly BrandDuplicateFinder $finder,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $perTermCap = max(1, (int) $this->option('per-term-cap'));
        $csvPath = (string) $this->option('csv');

        $this->info(
            '[READ-ONLY] brands:audit-woo-membership — per_term_cap='.$perTermCap
            .($csvPath !== '' ? " csv={$csvPath}" : '')
        );

        // ── 1. Discover duplicate groups (reuse the single source of truth) ──
        try {
            $rawPlan = $this->finder->discover();
        } catch (\Throwable $e) {
            $this->warn("  ! brands discovery failed: {$e->getMessage()}");
            $this->auditor->record('brands.audit_discovery_failed', [
                'error' => $e->getMessage(),
            ]);

            return SymfonyCommand::FAILURE;
        }

        if ($rawPlan === []) {
            $this->info('No duplicate brand groups found — nothing to audit.');

            return SymfonyCommand::SUCCESS;
        }

        // ── 2. Fetch membership once per DISTINCT term across all groups ─────
        // Cache by term id so a term appearing in >1 comparison is read once.
        /** @var array<int, array<int, array{sku:string,name:string}>> $membershipCache */
        $membershipCache = [];
        /** @var array<int, bool> $termMissing */
        $termMissing = [];

        $fetch = function (int $termId) use (&$membershipCache, &$termMissing, $perTermCap): array {
            if (isset($membershipCache[$termId])) {
                return $membershipCache[$termId];
            }

            $result = $this->fetchTermMembership($termId, $perTermCap);
            $membershipCache[$termId] = $result['members'];
            $termMissing[$termId] = $result['missing'];

            return $result['members'];
        };

        // ── 3. Walk groups, compute per-source comparison rows ───────────────
        /** @var array<int, array{group:string, canonical_id:int, source_id:int, product_id:int, sku:string, name:string, on_canonical:int, on_source:int}> $csvRows */
        $csvRows = [];
        /** @var array<int, array<int, string>> $tableRows */
        $tableRows = [];
        $groupsWithDisagreement = 0;

        foreach ($rawPlan as $key => $entry) {
            $canonical = $entry['canonical'];
            $canonicalId = (int) $canonical['id'];
            $canonicalMembers = $fetch($canonicalId);

            // Group-level "most products" winner across canonical + all sources.
            $termCounts = [$canonicalId => count($canonicalMembers)];
            $termMeta = [$canonicalId => $canonical];
            foreach ($entry['sources'] as $src) {
                $sid = (int) $src['id'];
                $termCounts[$sid] = count($fetch($sid));
                $termMeta[$sid] = $src;
            }
            arsort($termCounts); // most products first; PHP keeps insertion order on ties → canonical wins a tie
            $suggestedCanonicalId = (int) array_key_first($termCounts);
            $finderDisagrees = $suggestedCanonicalId !== $canonicalId;
            if ($finderDisagrees) {
                $groupsWithDisagreement++;
            }

            foreach ($entry['sources'] as $src) {
                $sourceId = (int) $src['id'];
                $sourceMembers = $fetch($sourceId);

                $canonicalIds = array_keys($canonicalMembers);
                $sourceIds = array_keys($sourceMembers);

                $onBoth = array_intersect($canonicalIds, $sourceIds);
                $onCanonicalOnly = array_diff($canonicalIds, $sourceIds);
                $onSourceOnly = array_diff($sourceIds, $canonicalIds);
                $distinct = array_unique(array_merge($canonicalIds, $sourceIds));

                // Audit row — the machine-readable record the operator/verifier reads.
                $this->auditor->record('brands.audit_group', [
                    'group_key' => (string) $key,
                    'canonical_id' => $canonicalId,
                    'canonical_name' => (string) $canonical['name'],
                    'canonical_slug' => (string) ($canonical['slug'] ?? ''),
                    'source_id' => $sourceId,
                    'source_name' => (string) $src['name'],
                    'source_slug' => (string) ($src['slug'] ?? ''),
                    'canonical_woo_count' => count($canonicalMembers),
                    'source_woo_count' => count($sourceMembers),
                    'on_canonical_only' => count($onCanonicalOnly),
                    'on_source_only' => count($onSourceOnly),
                    'on_both' => count($onBoth),
                    'distinct_total' => count($distinct),
                    'suggested_canonical_id' => $suggestedCanonicalId,
                    'suggested_canonical_name' => (string) ($termMeta[$suggestedCanonicalId]['name'] ?? ''),
                    'finder_disagrees' => $finderDisagrees,
                    'canonical_term_missing' => (bool) ($termMissing[$canonicalId] ?? false),
                    'source_term_missing' => (bool) ($termMissing[$sourceId] ?? false),
                ]);

                $tableRows[] = [
                    (string) $key,
                    $canonicalId.' / '.($canonical['slug'] ?? ''),
                    $sourceId.' / '.($src['slug'] ?? ''),
                    (string) count($canonicalMembers),
                    (string) count($sourceMembers),
                    (string) count($onCanonicalOnly),
                    (string) count($onSourceOnly),
                    (string) count($onBoth),
                    (string) count($distinct),
                    $suggestedCanonicalId.' ('.($termMeta[$suggestedCanonicalId]['name'] ?? '').')',
                    $finderDisagrees ? 'YES — finder picks '.$canonicalId : 'no',
                ];

                // CSV per-product rows across the canonical ∪ source union.
                if ($csvPath !== '') {
                    foreach ($distinct as $pid) {
                        $pid = (int) $pid;
                        $meta = $canonicalMembers[$pid] ?? $sourceMembers[$pid] ?? ['sku' => '', 'name' => ''];
                        $csvRows[] = [
                            'group' => (string) $key,
                            'canonical_id' => $canonicalId,
                            'source_id' => $sourceId,
                            'product_id' => $pid,
                            'sku' => (string) $meta['sku'],
                            'name' => (string) $meta['name'],
                            'on_canonical' => isset($canonicalMembers[$pid]) ? 1 : 0,
                            'on_source' => isset($sourceMembers[$pid]) ? 1 : 0,
                        ];
                    }
                }
            }
        }

        // ── 4. Per-group comparison table ────────────────────────────────────
        $this->newLine();
        $this->info('Woo brand-term membership per duplicate group (READ-ONLY):');
        $this->table(
            [
                'Group', 'Canonical id/slug', 'Source id/slug',
                'Canon count', 'Source count',
                'Canon only', 'Source only', 'Both', 'Distinct',
                'Suggested (most-products)', 'Finder disagrees?',
            ],
            $tableRows,
        );

        // ── 5. Summary ───────────────────────────────────────────────────────
        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['duplicate_groups', count($rawPlan)],
                ['comparison_rows', count($tableRows)],
                ['groups_where_finder_disagrees', $groupsWithDisagreement],
            ],
        );

        if ($groupsWithDisagreement > 0) {
            $this->warn(
                "  ! {$groupsWithDisagreement} group(s) where the current finder canonical is NOT the "
                .'most-populated Woo term. Do NOT run brands:dedupe --delete-empty-woo-terms until the '
                .'canonical fix + Woo-aware re-tag follow-up ships.'
            );
        }

        // ── 6. Optional CSV ──────────────────────────────────────────────────
        if ($csvPath !== '') {
            $this->writeCsv($csvPath, $csvRows);
            $this->info('  wrote '.count($csvRows)." per-product rows → {$csvPath}");
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * READ-ONLY paginated fetch of the products carrying a Woo brand term.
     *
     * GET products?brand=<termId>&per_page=100&page=<n>&status=any — increments
     * the page (a pure read never shrinks the filter set), de-dups by product id,
     * stops on short/empty page or when $cap product ids have been collected.
     * A 404 (term deleted between discovery and now) is reported as empty
     * membership + a `brands.audit_term_missing` audit row, NOT a failure.
     *
     * @return array{members: array<int, array{sku:string,name:string}>, missing: bool, cap_hit: bool}
     */
    private function fetchTermMembership(int $termId, int $cap): array
    {
        /** @var array<int, array{sku:string,name:string}> $members */
        $members = [];
        $capHit = false;
        $page = 1;

        while ($page <= self::MAX_PAGES_PER_TERM) {
            try {
                $response = $this->woo->get('products', [
                    'brand' => $termId,
                    'per_page' => self::PRODUCTS_PER_PAGE,
                    'page' => $page,
                    'status' => 'any',
                ]);
            } catch (\Throwable $e) {
                $is404 = ((int) $e->getCode() === 404)
                    || str_contains($e->getMessage(), 'term does not exist')
                    || str_contains($e->getMessage(), 'rest_term_invalid');

                if ($is404) {
                    $this->line("  audit_term_missing term={$termId} (deleted between discovery and now — counted as 0 products)");
                    $this->auditor->record('brands.audit_term_missing', [
                        'term_id' => $termId,
                    ]);

                    return ['members' => [], 'missing' => true, 'cap_hit' => false];
                }

                // Non-404 read error — record + treat as empty for this term so the
                // rest of the audit still runs. (READ-ONLY: nothing to roll back.)
                $this->warn("  ! products GET term={$termId} failed: {$e->getMessage()}");
                $this->auditor->record('brands.audit_term_read_failed', [
                    'term_id' => $termId,
                    'error' => $e->getMessage(),
                ]);

                return ['members' => $members, 'missing' => false, 'cap_hit' => $capHit];
            }

            if (! is_array($response) || $response === []) {
                break;
            }

            foreach ($response as $row) {
                if (! is_array($row)) {
                    $row = json_decode((string) json_encode($row), true);
                }
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (! isset($members[$id])) {
                    $members[$id] = [
                        'sku' => (string) ($row['sku'] ?? ''),
                        'name' => (string) ($row['name'] ?? ''),
                    ];
                }
                if (count($members) >= $cap) {
                    $capHit = true;
                    break;
                }
            }

            if ($capHit) {
                $this->warn("  ! audit_term_cap_hit term={$termId} — stopped at cap={$cap} products (there may be more on this term)");
                $this->auditor->record('brands.audit_term_cap_hit', [
                    'term_id' => $termId,
                    'cap' => $cap,
                ]);
                break;
            }

            if (count($response) < self::PRODUCTS_PER_PAGE) {
                break;
            }

            $page++;
        }

        return ['members' => $members, 'missing' => false, 'cap_hit' => $capHit];
    }

    /**
     * Write per-product rows to a CSV. Creates the parent directory if needed.
     *
     * @param  array<int, array{group:string, canonical_id:int, source_id:int, product_id:int, sku:string, name:string, on_canonical:int, on_source:int}>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->warn("  ! could not open CSV for writing: {$path}");
            $this->auditor->record('brands.audit_csv_write_failed', ['path' => $path]);

            return;
        }

        fputcsv($handle, [
            'group', 'canonical_id', 'source_id', 'product_id', 'sku', 'name', 'on_canonical', 'on_source',
        ]);
        foreach ($rows as $r) {
            fputcsv($handle, [
                $r['group'],
                (string) $r['canonical_id'],
                (string) $r['source_id'],
                (string) $r['product_id'],
                $r['sku'],
                $r['name'],
                (string) $r['on_canonical'],
                (string) $r['on_source'],
            ]);
        }
        fclose($handle);
    }
}
