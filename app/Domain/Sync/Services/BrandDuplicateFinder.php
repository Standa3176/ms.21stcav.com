<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use Illuminate\Support\Str;

/**
 * Single source of truth for Woo brand-duplicate discovery (260613-f2r).
 *
 * Pages through `/wp-json/wc/v3/products/brands?per_page=100`, groups by
 * `strtolower(trim($name))`, drops singletons, and picks a canonical row per
 * group via slug-quality rank ASC (exact base-slug < clean < numeric-suffix
 * < -brand-suffix), tie-break lowest id ASC. Returns the [canonical, sources]
 * split keyed by the lowercased+trimmed name.
 *
 * **2026-06-13 INCIDENT — why slug, not count:** this service originally ranked
 * canonical by Woo's `count` field DESC. At probe time the `-brand`-suffix
 * duplicates (`barco-brand`, `crestron-brand`, `lg-brand`, `neat-brand`,
 * `yealink-brand`) had higher stale taxonomy counts than the clean-slug
 * originals, so the service told the operator to treat the wrong rows as
 * canonical. The operator deleted the (truly clean) source thinking it was
 * the dup → 13 Barco products were orphaned and ~3 hours of hand-rescue
 * burned. Slug shape is immutable; the WC taxonomy `count` is a delayed
 * cache. See `rankSlug()` PHPDoc for the full ranking semantics.
 *
 * `count` is still pulled off Woo and propagated through `discover()` for
 * display/audit purposes (DedupeBrandsCommand's dry-run table), but it is
 * NEVER consulted for canonical selection.
 *
 * Consumed by:
 *   - DedupeBrandsCommand   (260613-dir) — phase A reassigns MS products.brand_id
 *     from sources → canonical; phase B optionally DELETEs the Woo source terms.
 *   - RetagProductsOnWooCommand (260613-f2r) — re-tags Woo products from
 *     source brand terms → canonical brand terms so the source terms have
 *     Woo count=0 before any phase-B delete cascade strips them from products.
 *
 * **Drift-prevention contract:** if a third consumer is added or the canonical-
 * pick rule changes (e.g. "prefer the source with the most recent created_at"),
 * edit HERE only — do NOT re-implement pagination + grouping + canonical-pick
 * in commands. The same rule applies if a future quick task adds variation
 * brand dedupe — extend this service, not the consumers.
 *
 * **Pagination failure semantics:** any throw from `$this->woo->get()` bubbles
 * to the caller. Consumers wrap `$this->finder->discover()` in their own
 * try/catch and decide whether to audit + exit FAILURE (DedupeBrandsCommand's
 * `brands.dedupe_pagination_failed` precedent) or to audit + exit SUCCESS.
 *
 * **`planned_affected` is intentionally NOT included** in the return value —
 * the DB::count() per-source step is command-specific (DedupeBrandsCommand
 * needs it for its dry-run table; RetagProductsOnWooCommand doesn't care).
 * Consumers compute it AFTER calling discover() if needed.
 */
final class BrandDuplicateFinder
{
    /**
     * Woo REST per-page cap. Mirrors DedupeBrandsCommand's BRANDS_PER_PAGE
     * exactly — if Woo bumps the cap or we want to lower it (e.g. for slow
     * installs), edit HERE only.
     */
    private const BRANDS_PER_PAGE = 100;

    public function __construct(
        private readonly WooClient $woo,
    ) {}

    /**
     * Discover duplicate brand groups on Woo.
     *
     * @return array<string, array{
     *     canonical: array{id:int,name:string,count:int,slug:string},
     *     sources: array<int, array{id:int,name:string,count:int,slug:string}>
     * }>
     *   Keyed by strtolower(trim($name)). Only groups with 2+ rows are
     *   returned. Within each entry: canonical = winner (slug-quality rank
     *   ASC — see {@see rankSlug()} — tie-break lowest id ASC); sources =
     *   the rest (zero-or-more).
     *
     *   Each row carries `count` (read off Woo for display/audit) but
     *   `count` is NOT used for canonical selection — see the class-level
     *   PHPDoc for the 2026-06-13 incident context.
     */
    public function discover(): array
    {
        // ── 1. Page through Woo brands ───────────────────────────────────────
        /** @var array<int, array{id:int,name:string,count:int,slug:string}> $brands */
        $brands = [];
        $page = 1;
        while (true) {
            $response = $this->woo->get('products/brands', [
                'per_page' => self::BRANDS_PER_PAGE,
                'page' => $page,
            ]);

            if (! is_array($response) || $response === []) {
                break;
            }

            foreach ($response as $row) {
                // WooClient returns stdClass for list endpoints — normalise to
                // assoc array so the foreach below uses uniform array access.
                // Same fix pattern as 260609-nku (commit 9581de8 for
                // BackfillCategoryFromWooCommand) and PushDivergenceToWooCommand.
                if (! is_array($row)) {
                    $row = json_decode((string) json_encode($row), true);
                }
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
                    // `count` is still surfaced for display/audit (the
                    // dry-run table in DedupeBrandsCommand reads it). It is
                    // NOT used for canonical selection — see 2026-06-13
                    // incident note on the class docblock + rankSlug().
                    'count' => (int) ($row['count'] ?? 0),
                    // `slug` drives the new canonical ranking (260613-o33).
                    'slug' => (string) ($row['slug'] ?? ''),
                ];
            }

            // Defensive — if Woo returned less than per_page, no more pages.
            if (count($response) < self::BRANDS_PER_PAGE) {
                break;
            }

            $page++;
        }

        // ── 2. Group by lowercased + trimmed name ────────────────────────────
        /** @var array<string, array<int, array{id:int,name:string,count:int,slug:string}>> $allGroups */
        $allGroups = [];
        foreach ($brands as $brand) {
            $key = strtolower(trim($brand['name']));
            $allGroups[$key][] = $brand;
        }

        /** @var array<string, array<int, array{id:int,name:string,count:int,slug:string}>> $groups */
        $groups = array_filter($allGroups, static fn (array $g): bool => count($g) > 1);

        // ── 3. Determine canonical + split sources per group ─────────────────
        /** @var array<string, array{canonical:array{id:int,name:string,count:int,slug:string}, sources:array<int,array{id:int,name:string,count:int,slug:string}>}> $plan */
        $plan = [];
        foreach ($groups as $key => $group) {
            // 2026-06-13 INCIDENT — do NOT use WC's count field for canonical
            // selection. It's a stale taxonomy cache; ranking by count picked
            // the wrong canonical for 4 brand groups (Barco, Crestron, LG,
            // Neat, Yealink — all with high-count `*-brand` duplicates) and
            // the operator deleted the clean source thinking it was the dup,
            // orphaning 13 Barco products. Slug shape is immutable; count
            // is not. See rankSlug() for ranking semantics.
            $expectedBaseSlug = Str::slug($key); // $key is already strtolower(trim($name))
            usort($group, function (array $a, array $b) use ($expectedBaseSlug): int {
                $ra = $this->rankSlug($a['slug'], $expectedBaseSlug);
                $rb = $this->rankSlug($b['slug'], $expectedBaseSlug);
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }

                return $a['id'] <=> $b['id'];
            });
            $canonical = $group[0];
            $sources = array_slice($group, 1);

            $plan[$key] = [
                'canonical' => $canonical,
                'sources' => $sources,
            ];
        }

        return $plan;
    }

    /**
     * Rank a Woo brand slug by quality relative to the expected base slug.
     *
     *   0 = exact base-slug match           (e.g. slug='barco'        for name 'Barco')
     *   1 = clean variant                   (e.g. slug='barcoshop'    — no numeric, no -brand)
     *   2 = WC dedup numeric suffix         (e.g. slug='barco-1', 'barco-2')
     *   3 = legacy `-brand` suffix          (e.g. slug='barco-brand')
     *
     * Lower rank = higher quality = preferred as canonical.
     *
     * 2026-06-13 INCIDENT context: BrandDuplicateFinder used to rank by WC's
     * `count` field. That field is a delayed taxonomy cache — at probe time
     * the `-brand`-suffix dups had higher stale counts than the clean-slug
     * originals, so we picked the WRONG canonical for Barco / Crestron / LG /
     * Neat / Yealink. Operator then deleted the clean source thinking it was
     * the dup → 13 Barco products orphaned, ~3 hours of hand-rescue.
     *
     * Slug shape is immutable; count is not. Therefore: rank by slug.
     */
    private function rankSlug(string $slug, string $expectedBaseSlug): int
    {
        if ($slug === $expectedBaseSlug) {
            return 0;
        }
        if (str_ends_with($slug, '-brand')) {
            return 3;
        }
        if (preg_match('/-\d+$/', $slug) === 1) {
            return 2;
        }

        return 1;
    }
}
