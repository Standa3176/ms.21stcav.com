<?php

declare(strict_types=1);

namespace App\Domain\Agents\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 12 Plan 02 — Brand slug resolver (P12-C mitigation).
 *
 * Resolves a Product.brand_id to the canonical lowercase slug used to route
 * to per-brand brand-voice markdown at resources/agents/brand-voice/{slug}.md.
 *
 * Why a dedicated helper?
 *   - P12-C pitfall: tools that receive 'Logitech, Inc.' (the brand display
 *     name) and try to read 'logitech,-inc..md' silently fall back to the
 *     global voice file. By centralising slug derivation against the
 *     authoritative brands.slug column, all callers route consistently.
 *   - ReadProductDraftTool (Plan 12-02) AND RunSeoAgentJob (Plan 12-04) both
 *     call forBrandId() — single source of truth.
 *
 * Resolution rules:
 *   - $brandId === null → 'global' (defensive — eligibility query in Plan
 *     12-05 already filters out brand_id=null drafts, but if one slips
 *     through we route to the global voice rather than crashing).
 *   - brands row exists with non-null slug → slug.
 *   - brands row missing OR slug column null → (string) $brandId (numeric
 *     fallback — the per-brand file lookup will simply fall through to
 *     _global.md in the read tool, which is the correct degraded path).
 *   - brands table does not exist in this schema (the meetingstore-ops-app
 *     v2.0 catalogue has no brands table yet — brand_id is currently a
 *     stub identifier) → (string) $brandId.
 *
 * Caching: rememberForever per brand_id — slug never changes for a brand.
 * Test-side: tests must call Cache::flush() between cases to avoid
 * cross-test contamination (the test class already does this in setUp).
 */
final class BrandSlugResolver
{
    public static function forBrandId(?int $brandId): string
    {
        if ($brandId === null) {
            return 'global';
        }

        return cache()->rememberForever(
            "brand_slug.{$brandId}",
            static fn (): string => self::lookupSlug($brandId)
        );
    }

    private static function lookupSlug(int $brandId): string
    {
        try {
            if (! Schema::hasTable('brands')) {
                return (string) $brandId;
            }
            $slug = DB::table('brands')->where('id', $brandId)->value('slug');
        } catch (QueryException | Throwable) {
            // Defensive: any DB issue (missing column, drift) degrades to
            // the numeric fallback rather than blocking an agent run.
            return (string) $brandId;
        }

        if (! is_string($slug) || $slug === '') {
            return (string) $brandId;
        }

        return $slug;
    }
}
