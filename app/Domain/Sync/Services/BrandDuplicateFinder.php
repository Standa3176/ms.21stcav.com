<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Single source of truth for Woo brand-duplicate discovery (260613-f2r).
 *
 * Pages through `/wp-json/wc/v3/products/brands?per_page=100`, groups by
 * `strtolower(trim($name))`, drops singletons, and picks a canonical row per
 * group via highest count DESC, tie-break lowest id ASC. Returns the
 * [canonical, sources] split keyed by the lowercased+trimmed name.
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
     *     canonical: array{id:int,name:string,count:int},
     *     sources: array<int, array{id:int,name:string,count:int}>
     * }>
     *   Keyed by strtolower(trim($name)). Only groups with 2+ rows are
     *   returned. Within each entry: canonical = winner (highest count DESC,
     *   tie-break lowest id ASC); sources = the rest (zero-or-more).
     */
    public function discover(): array
    {
        // ── 1. Page through Woo brands ───────────────────────────────────────
        /** @var array<int, array{id:int,name:string,count:int}> $brands */
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
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }

            // Defensive — if Woo returned less than per_page, no more pages.
            if (count($response) < self::BRANDS_PER_PAGE) {
                break;
            }

            $page++;
        }

        // ── 2. Group by lowercased + trimmed name ────────────────────────────
        /** @var array<string, array<int, array{id:int,name:string,count:int}>> $allGroups */
        $allGroups = [];
        foreach ($brands as $brand) {
            $key = strtolower(trim($brand['name']));
            $allGroups[$key][] = $brand;
        }

        /** @var array<string, array<int, array{id:int,name:string,count:int}>> $groups */
        $groups = array_filter($allGroups, static fn (array $g): bool => count($g) > 1);

        // ── 3. Determine canonical + split sources per group ─────────────────
        /** @var array<string, array{canonical:array{id:int,name:string,count:int}, sources:array<int,array{id:int,name:string,count:int}>}> $plan */
        $plan = [];
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

            $plan[$key] = [
                'canonical' => $canonical,
                'sources' => $sources,
            ];
        }

        return $plan;
    }
}
