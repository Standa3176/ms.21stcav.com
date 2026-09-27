<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Quick task 260927-p41 — estimate weekly UK web demand for a SKU.
 *
 * WHY THIS EXISTS: meetingstore.co.uk has taken no orders for about a year, so
 * the input every ad-ranking normally uses — units sold — does not exist.
 * `products.last_sales_count_90d` is a year stale at best and zero at worst, and
 * GA4 has nothing to say about a dark shop. Ranking ad candidates on margin
 * alone would put the catalogue's most expensive niche kit at the top, because
 * cash margin rises with price while web demand falls with it.
 *
 * WHAT IS MEASURED VS ASSUMED — the honest split, because it governs how much
 * weight the output can carry:
 *
 *   MEASURED   breadth      how many tracked resellers currently list the SKU
 *              persistence  on how many distinct days it appeared in the window
 *              price        our own net sell price
 *
 *   ASSUMED    class base rate (units/week nationally for this KIND of product)
 *              price-band decay
 *              the weighting of breadth and persistence
 *
 * The assumed parts live in config/ad_demand.php with their reasoning, because
 * the class base rates are the dominant source of error and an AV trader knows
 * them better than this codebase does.
 *
 * THIS IS A RANKING INSTRUMENT, NOT A FORECAST. The estimate is good for "is A
 * likely to sell more than B", which is all a shortlist needs. It is not good
 * for "we will sell 7 next week". band() exists so the output can be read at the
 * precision it actually supports, and estimate() returns every factor so a row
 * can be argued with rather than taken on faith.
 *
 * The one input that would make this a measurement is Google Keyword Planner
 * search volume (free, UK location) against the exported SKU list.
 *
 * Pure: no DB, no HTTP, no clock. Everything comes in as arguments.
 */
final class WebDemandEstimator
{
    /**
     * Keyword => class, tested in order, FIRST MATCH WINS. Order matters, and
     * each of these was an actual mis-class before it was ordered this way:
     *
     *   accessory BEFORE everything — "wall mount for 55in display" is a £40
     *     bracket, not a screen.
     *   installed_av BEFORE the device classes — "Shure MXA910 Ceiling Array
     *     Microphone" matched 'microphone' and came out a speakerphone, putting a
     *     £150 USB-device demand rate on a £3k specified ceiling array.
     *   uc_bar BEFORE display — a "video bar" is not a display, and 'bar' alone
     *     is too weak a token to lead with.
     *
     * Matching is on the product title, which is the only prose we hold locally
     * for every product (supplier_products carries no description column — see
     * the 260914-j0x grounding work). A title that matches nothing returns
     * 'unknown', which is a mid-table prior rather than a silent drop.
     *
     * @var array<string, array<int, string>>
     */
    private const CLASS_KEYWORDS = [
        // Cables, brackets, plates, PSUs — cheap, high-volume, web-bought.
        'accessory' => [
            'cable', 'adapter', 'adaptor', 'bracket', 'mount', 'wall plate', 'faceplate',
            'stand', 'trolley', 'cart', 'pole', 'column', 'shelf', 'fixing kit',
            'power supply', 'psu', 'charger', 'dock', 'hub', 'splitter', 'patch',
            'connector', 'coupler', 'lead', 'remote control', 'battery', 'case',
            'cover', 'pouch', 'strap', 'ear cushion', 'eartip', 'spare',
        ],
        'installed_av' => [
            'dsp', 'matrix', 'amplifier', 'ceiling', 'array', 'processor', 'controller',
            'rack', 'transmitter', 'receiver', 'extender', 'encoder', 'decoder',
            'distribution', 'scaler', 'converter', 'installation',
        ],
        'headset' => ['headset', 'headphone', 'earbud', 'earphone', 'earset'],
        'webcam' => ['webcam', 'web cam', 'personal camera', 'usb camera'],
        'speakerphone' => ['speakerphone', 'speaker phone', 'conference phone', 'microphone', 'mic pod', 'table mic'],
        // Video bars / all-in-one UC devices and soundbars.
        'uc_bar' => ['video bar', 'videobar', 'soundbar', 'sound bar', 'collaboration bar', 'meeting bar', 'rally bar', 'studio bar', 'all-in-one', 'room system', 'room kit', 'room bundle'],
        'networking' => ['switch', 'router', 'access point', 'wireless ap', 'poe injector', 'network card'],
        'projector' => ['projector', 'projection'],
        // Interactive panels, large-format screens — specified and installed.
        'display_large' => ['interactive', 'flip', 'whiteboard', 'video wall', 'led wall', 'large format', 'signage'],
        'display_small' => ['monitor', 'display', 'screen', 'panel', 'television', ' tv '],
        'licence' => ['licence', 'license', 'subscription', 'warranty', 'care pack', 'support pack', 'service plan'],
    ];

    /**
     * Estimate weekly UK web units for one product.
     *
     * @param  string  $name  product title — the only local prose for classing
     * @param  int  $netSellPence  our sell price EX-VAT (same basis as margin)
     * @param  int  $competitorCount  DISTINCT tracked resellers currently listing it
     * @param  int  $daysSeen  distinct days it appeared in the window
     * @param  int  $windowDays  the window those sightings were counted over
     * @return array{
     *     units_per_week: float,
     *     band: string,
     *     class: string,
     *     class_base: float,
     *     price_factor: float,
     *     price_band: string,
     *     breadth_factor: float,
     *     persistence_factor: float
     * }
     */
    public function estimate(
        string $name,
        int $netSellPence,
        int $competitorCount,
        int $daysSeen,
        int $windowDays,
    ): array {
        $class = $this->classify($name);
        $classBase = $this->classBase($class);
        [$priceFactor, $priceBand] = $this->priceBand($netSellPence);
        $breadthFactor = $this->breadthFactor($competitorCount);
        $persistenceFactor = $this->persistenceFactor($daysSeen, $windowDays);

        $units = $classBase * $priceFactor * $breadthFactor * $persistenceFactor;

        return [
            'units_per_week' => round($units, 2),
            'band' => $this->band($units),
            'class' => $class,
            'class_base' => $classBase,
            'price_factor' => $priceFactor,
            'price_band' => $priceBand,
            'breadth_factor' => $breadthFactor,
            'persistence_factor' => round($persistenceFactor, 2),
        ];
    }

    /**
     * Expected weekly gross profit in pence — the number a shortlist should
     * actually be ranked on. Cash margin alone favours expensive niche kit;
     * estimated volume alone favours cables nobody makes money on. The product
     * of the two is what an ad budget is competing for.
     */
    public function weeklyProfitPence(float $unitsPerWeek, int $marginPence): int
    {
        return (int) round($unitsPerWeek * $marginPence);
    }

    /**
     * First keyword match wins — see CLASS_KEYWORDS for why the order is not
     * alphabetical. The haystack is padded with spaces so a keyword written with
     * surrounding spaces (' tv ') anchors on word boundaries without a regex.
     */
    public function classify(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';
        $haystack = ' '.strtolower($collapsed).' ';

        foreach (self::CLASS_KEYWORDS as $class => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $class;
                }
            }
        }

        return 'unknown';
    }

    private function classBase(string $class): float
    {
        /** @var array<string, float> $bases */
        $bases = (array) config('ad_demand.class_base_units_per_week', []);

        return (float) ($bases[$class] ?? $bases['unknown'] ?? 1.0);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function priceBand(int $netSellPence): array
    {
        /** @var array<int, array{max_net_pence:int, factor:float, label:string}> $bands */
        $bands = (array) config('ad_demand.price_band_factors', []);

        foreach ($bands as $band) {
            if ($netSellPence <= (int) $band['max_net_pence']) {
                return [(float) $band['factor'], (string) $band['label']];
            }
        }

        return [1.0, 'unbanded'];
    }

    /**
     * Breadth above the largest configured key keeps that key's factor — five
     * tracked competitors is the current ceiling, and a sixth feed arriving
     * should widen the table deliberately rather than silently extrapolate.
     */
    private function breadthFactor(int $competitorCount): float
    {
        /** @var array<int, float> $factors */
        $factors = (array) config('ad_demand.breadth_factors', []);
        if ($factors === []) {
            return 1.0;
        }

        if ($competitorCount <= 0) {
            return (float) min($factors);
        }

        $max = max(array_keys($factors));

        return (float) ($factors[min($competitorCount, $max)] ?? min($factors));
    }

    /**
     * Scales between the configured floor (seen once) and 1.0 (seen on every day
     * a feed was expected). A SKU that appeared in one feed and never again is
     * usually clearance rather than a stocked line — worth less, not worthless.
     */
    private function persistenceFactor(int $daysSeen, int $windowDays): float
    {
        $floor = (float) config('ad_demand.persistence_floor', 0.4);
        $perWeek = (float) config('ad_demand.expected_sightings_per_week', 2.0);

        $expected = max(1.0, ($windowDays / 7) * $perWeek);
        $ratio = min(1.0, max(0.0, $daysSeen / $expected));

        return $floor + ((1.0 - $floor) * $ratio);
    }

    /**
     * The model cannot justify decimal precision, so callers should display this
     * next to (or instead of) units_per_week.
     */
    public function band(float $unitsPerWeek): string
    {
        /** @var array<int, array{max_units:float, label:string}> $bands */
        $bands = (array) config('ad_demand.bands', []);

        foreach ($bands as $band) {
            if ($unitsPerWeek <= (float) $band['max_units']) {
                return (string) $band['label'];
            }
        }

        return 'unbanded';
    }
}
