<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use Illuminate\Support\Carbon;

/**
 * 260712-mdr — Marketing dashboard date-range resolver (single source of truth).
 *
 * Maps a preset key (+ optional custom from/to pickers) to a concrete
 * [from, to] window expressed as driver-portable Y-m-d strings so BOTH the
 * MarketingOverviewStats tiles and the MarketingRevenueTrendChart resolve the
 * SAME window identically. Pure value object — no DB, no Google calls, no writes.
 *
 * Rules:
 *   7d / 30d / 90d = trailing N-1 days inclusive of today
 *   ytd            = Jan 1 this year → today
 *   all            = a wide floor (2000-01-01) → today
 *   custom         = the two pickers; blank/invalid bound → fall back to 90d
 *   default        = 90d (more useful than the old hardcoded 30 given sparse data)
 *
 * TZ = app default (Carbon::today()); dates are date-only (no time part) to keep
 * whereBetween('date', …) portable across SQLite tests and MariaDB prod.
 */
final readonly class MarketingDateRange
{
    /** Default preset when none/unknown/invalid is supplied. */
    public const DEFAULT = '90d';

    /** Wide floor for the "all time" preset. */
    public const FLOOR = '2000-01-01';

    /** Preset key → human label (also drives the filters Select options). */
    private const LABELS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'ytd' => 'This year',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    private function __construct(
        /** The effective (resolved) range key — never 'custom' when a fallback fired. */
        public string $range,
        /** Window start, Y-m-d. */
        public string $from,
        /** Window end, Y-m-d. */
        public string $to,
        /** Human label for widget descriptions. */
        public string $label,
    ) {}

    /**
     * Resolve a (range, from, to) triple to a concrete window.
     */
    public static function resolve(?string $range, ?string $from = null, ?string $to = null): self
    {
        $today = Carbon::today();
        $key = is_string($range) && $range !== '' ? $range : self::DEFAULT;

        return match ($key) {
            '7d' => self::trailing('7d', 7, $today),
            '30d' => self::trailing('30d', 30, $today),
            '90d' => self::trailing('90d', 90, $today),
            'ytd' => new self(
                'ytd',
                $today->copy()->startOfYear()->toDateString(),
                $today->toDateString(),
                self::LABELS['ytd'],
            ),
            'all' => new self(
                'all',
                self::FLOOR,
                $today->toDateString(),
                self::LABELS['all'],
            ),
            'custom' => self::custom($from, $to, $today),
            default => self::trailing(self::DEFAULT, 90, $today),
        };
    }

    /**
     * Preset options for the filters Select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LABELS;
    }

    /** Trailing N-day window inclusive of today (from = today - (N-1)). */
    private static function trailing(string $key, int $days, Carbon $today): self
    {
        return new self(
            $key,
            $today->copy()->subDays($days - 1)->toDateString(),
            $today->toDateString(),
            self::LABELS[$key],
        );
    }

    /** Custom pickers; any blank/invalid bound falls back to the 90d default. */
    private static function custom(?string $from, ?string $to, Carbon $today): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null || $end === null) {
            return self::trailing(self::DEFAULT, 90, $today);
        }

        // Defensive: honour reversed pickers by swapping rather than erroring.
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $fromStr = $start->toDateString();
        $toStr = $end->toDateString();

        return new self('custom', $fromStr, $toStr, "{$fromStr} → {$toStr}");
    }

    /** Strict Y-m-d parse; rejects blanks and overflow dates (e.g. 2026-13-40). */
    private static function parse(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat is lenient on overflow — reject anything that does not
        // round-trip byte-identically back to the supplied Y-m-d.
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed->startOfDay();
    }
}
