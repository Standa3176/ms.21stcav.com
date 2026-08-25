<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Quick task 260825-h2r — a competitor row that must not price a given SKU.
 *
 * Born from `CP4`, which is a Unicol/AVM ceiling mount here and a Crestron
 * control processor at AVITDirect. Both feeds are right about their own
 * product; the string collides. See the migration for the full account.
 *
 * DELIBERATELY NOT is_price_anomaly: that flag asserts the feed's price is
 * wrong. AVITDirect's is right. This records a different fact — the row belongs
 * to a different product — so anomaly readings keep their meaning.
 *
 * Keys are stored and compared NORMALISED (lower + trim), matching how
 * lowestCurrentCompetitorGross compares sku/mpn, so an exclusion cannot miss on
 * casing alone.
 *
 * The active set is cached briefly: it is consulted once per product inside a
 * full-catalogue pricing run (6,356 products on 2026-08-25), and it changes
 * only when an operator adds a row.
 */
final class CompetitorMatchExclusion extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'competitor.match_exclusions';

    public const CACHE_SECONDS = 300;

    protected $fillable = [
        'competitor_id',
        'match_key',
        'reason',
    ];

    protected $casts = [
        'competitor_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // Single normalisation choke point — mirrors ProductSupplierSku.
        self::saving(static function (self $row): void {
            $row->match_key = self::normalise((string) $row->match_key);
        });

        self::saved(static fn () => self::forgetCache());
        self::deleted(static fn () => self::forgetCache());
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public static function normalise(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * Remove the exclusion(s) for this competitor + key, returning the count.
     *
     * Lives on the MODEL, not the command: CompetitorPricesNeverPrunedTest
     * statically scans *Command.php files for `CompetitorPrice::query()` near a
     * `->delete()`, guarding COMP-07 (competitor_prices is immutable history and
     * the whole Phase 5 value proposition). The exclusion command legitimately
     * reads CompetitorPrice to report impact, so keeping any delete out of that
     * file lets the guard stay blunt — which is what makes it trustworthy.
     */
    public static function removeFor(?int $competitorId, string $key): int
    {
        $removed = self::query()
            ->where('match_key', self::normalise($key))
            ->where('competitor_id', $competitorId)
            ->delete();

        self::forgetCache();

        return (int) $removed;
    }

    public static function countFor(?int $competitorId, string $key): int
    {
        return self::query()
            ->where('match_key', self::normalise($key))
            ->where('competitor_id', $competitorId)
            ->count();
    }

    public static function forgetCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // A cache failure must never break a pricing run; the lookup below
            // falls through to a live query.
        }
    }

    /**
     * Is this competitor's row for this key excluded from pricing?
     *
     * Matches either an exclusion naming this competitor, or a competitor_id =
     * null row meaning "no competitor should price this string".
     */
    public static function excludes(?int $competitorId, string $key): bool
    {
        $needle = self::normalise($key);
        if ($needle === '') {
            return false;
        }

        foreach (self::activeSet() as $row) {
            if ($row['key'] !== $needle) {
                continue;
            }

            if ($row['competitor_id'] === null || $row['competitor_id'] === $competitorId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{competitor_id: int|null, key: string}>
     */
    public static function activeSet(): array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_SECONDS,
                static fn (): array => self::loadSet(),
            );
        } catch (\Throwable) {
            return self::loadSet();
        }
    }

    /**
     * @return array<int, array{competitor_id: int|null, key: string}>
     */
    private static function loadSet(): array
    {
        return self::query()
            ->get(['competitor_id', 'match_key'])
            ->map(static fn (self $row): array => [
                'competitor_id' => $row->competitor_id === null ? null : (int) $row->competitor_id,
                'key' => (string) $row->match_key,
            ])
            ->all();
    }
}
