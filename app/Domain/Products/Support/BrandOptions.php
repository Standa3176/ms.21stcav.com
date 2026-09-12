<?php

declare(strict_types=1);

namespace App\Domain\Products\Support;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Quick task 260912-f8x — brand id → name, for admin pickers.
 *
 * WHY THIS EXISTS
 *
 * `products.brand_id` is a WooCommerce term id held as a plain nullable bigint
 * with NO foreign key — there is no local brands table (see the pricing_rules
 * migration, Pitfall 7). So every admin form that touches a brand has, until
 * now, been a bare numeric input: to write a pricing rule for Neat you had to
 * already know Neat's term id, type it, and hope. Nothing on the screen told
 * you whether the number was right, and nothing in the table told a later
 * reader which brand a rule applied to.
 *
 * That is the difference between brand rules being usable by an operator and
 * being a thing only whoever wrote them can safely edit.
 *
 * Cached for an hour: the list comes from Woo over HTTP and is rendered on
 * every form load. A stale entry for an hour costs nothing — a brand created
 * minutes ago simply is not pickable yet, and the numeric id still works.
 *
 * Fails SOFT. If Woo is unreachable the picker falls back to whatever is
 * already selected rather than blocking the form: an operator editing a margin
 * must not be stopped by a taxonomy lookup.
 */
final class BrandOptions
{
    private const CACHE_KEY = 'brand_options.id_to_name';

    private const TTL_SECONDS = 3600;

    /**
     * Brand id => display name, ordered by name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, static function (): array {
                $out = [];
                foreach (app(TaxonomyResolver::class)->allBrands() as $term) {
                    $id = (int) ($term['id'] ?? 0);
                    $name = trim((string) ($term['name'] ?? ''));
                    if ($id > 0 && $name !== '') {
                        $out[$id] = $name;
                    }
                }
                asort($out, SORT_NATURAL | SORT_FLAG_CASE);

                return $out;
            });
        } catch (Throwable) {
            // Woo unreachable — the form still opens, the id still works.
            return [];
        }
    }

    /** Name for one id, or a readable fallback so a table never shows a bare number. */
    public static function name(?int $brandId): ?string
    {
        if ($brandId === null) {
            return null;
        }

        return self::all()[$brandId] ?? ('#'.$brandId.' (unknown)');
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
