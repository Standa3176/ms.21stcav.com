<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\ShoppingCandidateScanner;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260722-shc — ranked Google Shopping trial shortlist (READ-ONLY).
 *
 * Produces the shortlist of products worth trialling on Google Shopping:
 * saleable (fresh supplier stock), profitable (margin floor), competitive
 * (several competitors already list them) and Merchant-eligible (GTIN present,
 * simple + published).
 *
 * ── The demand caveat, stated up front ──
 * The operator asked for "high volume" meaning UK MARKET demand. This app has
 * no UK search-volume data — `last_sales_count_90d` and GA4 are both own-site
 * signals, which measure what we already sell, not what the market searches
 * for. So the ranking uses COMPETITOR BREADTH (how many competitors currently
 * list the SKU) as a proven-demand PROXY — competitors stock what sells — and
 * the command says so in its own output. True UK volume must be validated by
 * running the exported CSV through Google Keyword Planner (location: United
 * Kingdom).
 *
 * 260927-p41 — and the shop has now been dark for about a year, so own-site
 * signals are not merely weaker than market data, they are ABSENT. Breadth
 * alone also ranked a £9,000 LED wall above a £400 video bar, because it says
 * nothing about whether a product is web-bought at all. `--sort=profit` (the
 * new default) ranks on WebDemandEstimator's estimated weekly units x true cash
 * margin, so price-driven demand decay and product class both count. The
 * estimate's assumed inputs are in config/ad_demand.php.
 *
 * ── Read-only contract ──
 * No DB writes, no Woo REST calls, no Google/Merchant API calls, no feed
 * generation or upload. The only file this command writes is the CSV the
 * operator explicitly asks for via --csv. Pinned by
 * tests/Feature/Console/ShoppingCandidatesCommandTest.php ("is READ-ONLY" and
 * "makes no outbound HTTP call").
 *
 * ── Example (prod) ──
 *   php artisan products:shopping-candidates \
 *     --limit=200 --sort=score \
 *     --csv=storage/app/research/shopping-candidates.csv
 */
final class ShoppingCandidatesCommand extends BaseCommand
{
    protected $signature = 'products:shopping-candidates
        {--min-margin-pence=19900 : Minimum TRUE CASH margin in pence — stripVat(sell) - buy, both sides ex-VAT}
        {--min-competitors=2 : Minimum DISTINCT competitors currently listing the SKU}
        {--competitor-window-days=30 : How recent a competitor price must be to count}
        {--allow-missing-gtin : Keep (and flag) products with no EAN — Google will likely disapprove them}
        {--sort=profit : profit|demand|score|margin|competitors (profit = est weekly units x true cash margin)}
        {--min-units-per-week= : Drop rows whose estimated weekly demand is below this (default from config/ad_demand.php)}
        {--limit=200 : Shortlist size}
        {--preview=25 : How many shortlist rows to print to the console}
        {--live-stock : Additionally confirm each shortlisted SKU against the LIVE fresh-supplier feed}
        {--csv= : Write the full shortlist to this CSV path}';

    protected $description = 'Rank Google-Merchant-eligible, high-margin, competitor-validated products for a Google Shopping trial (read-only).';

    /** CSV header — mirrored by the command test. */
    private const CSV_HEADER = [
        'rank', 'sku', 'name', 'brand', 'brand_id', 'woo_product_id', 'ean', 'has_gtin',
        'buy_price_pence', 'net_sell_price_pence', 'sell_price_pence',
        'margin_pence', 'margin_pct', 'margin_pence_gross_basis',
        'competitor_count', 'lowest_competitor_gross_pence', 'position',
        'delta_vs_lowest_pence', 'stock', 'supplier_name', 'score',
        // 260927-p41 — the demand estimate and its inputs. The factor columns
        // are exported deliberately: they are what makes a row's estimate
        // arguable instead of an oracle.
        'demand_class', 'price_band', 'days_seen', 'demand_window_days',
        'factor_class_base', 'factor_price', 'factor_breadth', 'factor_persistence',
        'est_units_per_week', 'est_units_band', 'est_weekly_profit_pence',
    ];

    protected function perform(): int
    {
        $sort = (string) $this->option('sort');
        if (! in_array($sort, ShoppingCandidateScanner::SORTS, true)) {
            $this->error("Unknown --sort '{$sort}'. Use one of: ".implode(', ', ShoppingCandidateScanner::SORTS));

            return SymfonyCommand::FAILURE;
        }

        $minMarginPence = max(0, (int) $this->option('min-margin-pence'));
        $minCompetitors = max(0, (int) $this->option('min-competitors'));
        $windowDays = max(1, (int) $this->option('competitor-window-days'));
        $allowMissingGtin = (bool) $this->option('allow-missing-gtin');
        $limit = max(1, (int) $this->option('limit'));
        $preview = max(0, (int) $this->option('preview'));
        $csvPath = $this->option('csv') !== null ? trim((string) $this->option('csv')) : '';

        $this->newLine();
        $this->line('── products:shopping-candidates — Google Shopping shortlist (READ-ONLY) ──');
        $this->line(sprintf(
            '  min-margin %dp (%s) · min-competitors %d · competitor window %dd · GTIN %s',
            $minMarginPence,
            $this->pounds($minMarginPence),
            $minCompetitors,
            $windowDays,
            $allowMissingGtin ? 'optional (flagged)' : 'required',
        ));
        $this->line(sprintf('  sort=%s · limit=%d', $sort, $limit));

        $result = app(ShoppingCandidateScanner::class)->scan(
            minMarginPence: $minMarginPence,
            minCompetitors: $minCompetitors,
            competitorWindowDays: $windowDays,
            allowMissingGtin: $allowMissingGtin,
            sort: $sort,
            limit: $limit,
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $result['rows'];
        /** @var array<string, int> $funnel */
        $funnel = $result['funnel'];

        // 260927-p41 — the ad-viability floor on VOLUME. Under roughly one sale a
        // month Google never gathers enough conversion data to optimise, so the
        // campaign cannot learn however fat the margin is.
        $minUnits = $this->option('min-units-per-week') !== null
            ? (float) $this->option('min-units-per-week')
            : (float) config('ad_demand.min_units_per_week', 0.25);

        $demandDropped = 0;
        if ($minUnits > 0) {
            $before = count($rows);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (float) $r['est_units_per_week'] >= $minUnits,
            ));
            $demandDropped = $before - count($rows);
        }

        $liveDropped = 0;
        if ((bool) $this->option('live-stock') && $rows !== []) {
            [$rows, $liveDropped] = $this->confirmAgainstLiveStock($rows);
        }

        $this->renderFunnel($funnel, $minMarginPence, $minCompetitors, $windowDays, $allowMissingGtin);

        if ($demandDropped > 0) {
            $this->line(sprintf(
                '  demand floor (>= %.2f units/wk): dropped %d, kept %d',
                $minUnits,
                $demandDropped,
                count($rows),
            ));
        }

        if ($liveDropped > 0 || (bool) $this->option('live-stock')) {
            $this->line(sprintf(
                '  live-stock confirmation: dropped %d, kept %d',
                $liveDropped,
                count($rows),
            ));
        }

        if ($rows === []) {
            $this->newLine();
            $this->warn('No eligible Google Shopping candidates at these thresholds.');
            $this->renderDemandCaveat();

            return SymfonyCommand::SUCCESS;
        }

        $this->renderPreview($rows, $preview);

        if ($csvPath !== '') {
            $written = $this->writeCsv($csvPath, $rows);
            $this->newLine();
            $this->info("CSV written: {$written}  ({$this->plural(count($rows), 'row')})");
        } else {
            $this->newLine();
            $this->line('  Tip: re-run with --csv=storage/app/research/shopping-candidates.csv to export the full shortlist.');
        }

        $this->renderDemandCaveat();

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Gate-by-gate drop report. Every product in the catalogue lands in exactly
     * ONE bucket, so the drops plus ELIGIBLE always sum to "Products scanned" —
     * the operator can see precisely WHY the shortlist is the size it is.
     *
     * The missing-GTIN line is the one that pays: it quantifies how many
     * otherwise-perfect Shopping candidates are blocked purely on a missing
     * `products.ean`, i.e. the size of the EAN-backfill prize
     * (`products:backfill-merchant-feed`).
     *
     * @param  array<string, int>  $funnel
     */
    private function renderFunnel(
        array $funnel,
        int $minMarginPence,
        int $minCompetitors,
        int $windowDays,
        bool $allowMissingGtin,
    ): void {
        $remaining = $funnel['products_total'];

        $this->newLine();
        $this->line('── Eligibility funnel ───────────────────────────────────────');
        $this->line('    '.$this->padRight('Products scanned', 44).sprintf('%7d', $remaining));

        $gates = [
            ['not publish/simple', $funnel['dropped_not_publish_simple'], ''],
            ['no SKU / buy+sell price', $funnel['dropped_no_price_or_sku'], ''],
            [sprintf('margin < %dp (%s)', $minMarginPence, $this->pounds($minMarginPence)), $funnel['dropped_below_min_margin'], ''],
            ['no fresh in-stock supplier offer (7d)', $funnel['dropped_no_fresh_stock'], ''],
            [sprintf('competitors < %d (%dd window)', $minCompetitors, $windowDays), $funnel['dropped_below_min_competitors'], ''],
            [
                'missing GTIN (products.ean)'.($allowMissingGtin ? ' [allowed]' : ''),
                $funnel['dropped_missing_gtin'],
                $allowMissingGtin ? '' : '   ← EAN-backfill opportunity',
            ],
        ];

        foreach ($gates as [$label, $dropped, $note]) {
            $remaining -= $dropped;
            $this->line(
                '  − '.$this->padRight($label, 44)
                .sprintf('%7d  → %6d', $dropped, $remaining)
                .$note
            );
        }

        $this->line('    '.$this->padRight('= ELIGIBLE', 44).sprintf('%7s  → %6d', '', $funnel['eligible']));
        $this->line('    '.$this->padRight('  shortlisted (--limit)', 44).sprintf('%7s  → %6d', '', $funnel['returned']));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderPreview(array $rows, int $preview): void
    {
        $shown = $preview > 0 ? array_slice($rows, 0, $preview) : [];
        if ($shown === []) {
            return;
        }

        $this->newLine();
        $this->line('── Top candidates ───────────────────────────────────────────');

        $table = [];
        foreach ($shown as $i => $row) {
            $table[] = [
                $i + 1,
                $row['sku'],
                $this->truncate((string) $row['name'], 30),
                $this->truncate((string) ($row['brand'] ?? '—'), 12),
                $row['demand_class'],
                $this->pounds((int) $row['margin_pence']),
                sprintf('%.1f%%', ((int) $row['margin_pct_bps']) / 100),
                (int) $row['competitor_count'],
                (int) $row['days_seen'],
                // The band is the honest read; the number is shown because a
                // ranking needs an ordering.
                $row['est_units_band'],
                $this->pounds((int) $row['est_weekly_profit_pence']).'/wk',
                ((bool) $row['has_gtin']) ? 'yes' : 'NO',
            ];
        }

        $this->table(
            ['#', 'SKU', 'Name', 'Brand', 'Class', 'Margin', 'Margin%', 'Comps', 'Days', 'Est demand', 'Est profit', 'GTIN'],
            $table,
        );

        if (count($rows) > count($shown)) {
            $this->line(sprintf(
                '  Showing %d of %d shortlisted — use --csv to export the full list (or raise --preview).',
                count($shown),
                count($rows),
            ));
        }
    }

    /**
     * Optional live confirmation of the shortlist against the supplier feed.
     *
     * WHY OPT-IN AND WHY ONLY THE SHORTLIST: LiveSupplierStockResolver
     * (260713-rsp) is the churn-safe "is this SKU listed by a FRESH supplier
     * right now" signal, but it issues ONE external supplier_db query per SKU.
     * Running it across the whole catalogue would be exactly the N+1 the bulk
     * gates avoid, so the bulk gate stays snapshot-based (one windowed pass,
     * mirroring AdCandidateScanner) and this pass is applied only to the
     * already-ranked, already-limited shortlist — bounded by --limit.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function confirmAgainstLiveStock(array $rows): array
    {
        $resolver = app(LiveSupplierStockResolver::class);

        $kept = [];
        $dropped = 0;
        foreach ($rows as $row) {
            if ($resolver->isListedByFreshSupplier((string) $row['sku'])) {
                $kept[] = $row;

                continue;
            }
            $dropped++;
        }

        return [$kept, $dropped];
    }

    /**
     * Write the FULL shortlist (header + one row per product). This is the file
     * the operator feeds into Google Keyword Planner and, later, Merchant
     * Center prep.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return string the resolved absolute path
     */
    private function writeCsv(string $path, array $rows): string
    {
        $resolved = $this->resolvePath($path);
        File::ensureDirectoryExists(dirname($resolved));

        $handle = fopen($resolved, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV for writing: {$resolved}");
        }

        fputcsv($handle, self::CSV_HEADER);
        foreach ($rows as $i => $row) {
            fputcsv($handle, [
                $i + 1,
                $row['sku'],
                $row['name'],
                $row['brand'] ?? '',
                $row['brand_id'] ?? '',
                $row['woo_product_id'] ?? '',
                $row['ean'] ?? '',
                ((bool) $row['has_gtin']) ? 'yes' : 'no',
                $row['buy_price_pence'],
                $row['net_sell_price_pence'],
                $row['sell_price_pence'],
                $row['margin_pence'],
                sprintf('%.2f', ((int) $row['margin_pct_bps']) / 100),
                $row['margin_pence_gross_basis'],
                $row['competitor_count'],
                $row['lowest_comp_pence'],
                $row['position'],
                $row['delta_vs_lowest_pence'],
                $row['stock'],
                $row['supplier_name'] ?? '',
                $row['score'],
                $row['demand_class'],
                $row['price_band'],
                $row['days_seen'],
                $row['demand_window_days'],
                $row['demand_factors']['class_base'],
                $row['demand_factors']['price'],
                $row['demand_factors']['breadth'],
                $row['demand_factors']['persistence'],
                $row['est_units_per_week'],
                $row['est_units_band'],
                $row['est_weekly_profit_pence'],
            ]);
        }
        fclose($handle);

        return $resolved;
    }

    /**
     * The single most important line of output: nobody should read this
     * shortlist as a UK search-volume ranking, because the app has none.
     */
    private function renderDemandCaveat(): void
    {
        $this->newLine();
        $this->line('── How to read this ─────────────────────────────────────────');
        $this->warn('  "Est demand" is MODELLED, not measured. Read the BAND, not the number.');
        $this->line('');
        $this->line('  The shop has taken no orders for about a year, so units-sold does not exist');
        $this->line('  as a ranking input (last_sales_count_90d is stale-or-zero; GA4 has nothing');
        $this->line('  to say about a dark site). The estimate is built from:');
        $this->line('');
        $this->line('    MEASURED   breadth      how many tracked resellers list the SKU now');
        $this->line('               persistence  on how many distinct days it appeared (Days)');
        $this->line('               price        our own net sell price');
        $this->line('    ASSUMED    class base rate — units/week nationally for this KIND of');
        $this->line('               product — plus the price-decay and weighting tables');
        $this->line('');
        $this->line('  The ASSUMED parts live in config/ad_demand.php with their reasoning. The');
        $this->line('  class base rates are the biggest source of error and the thing a trader');
        $this->line('  knows better than this codebase — correct them there and re-run; the');
        $this->line('  shortlist re-ranks. The exported CSV carries every factor per row so any');
        $this->line('  single estimate can be argued with.');
        $this->line('');
        $this->warn('  TO MAKE IT A MEASUREMENT: run the exported sku/name column through Google');
        $this->line('  Keyword Planner (free, location: United Kingdom). That is real search');
        $this->line('  volume for the top of the funnel and it is the one input missing here.');
        $this->line('');
        $this->line('  Margin is TRUE CASH margin — stripVat(sell) - buy, both sides ex-VAT');
        $this->line('  (260927-p41). The column margin_pence_gross_basis in the CSV is what an');
        $this->line('  export made before that fix would have claimed, for reconciliation.');
    }

    private function resolvePath(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($normalised, '/')
            || preg_match('/^[A-Za-z]:\//', $normalised) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    private function pounds(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }

    /**
     * Pad to a visual width. sprintf('%-44s') pads by BYTES, so a label
     * containing '£' or '←' silently loses a column of alignment — the funnel
     * is the operator's primary read, so it has to line up.
     */
    private function padRight(string $value, int $width): string
    {
        $pad = $width - mb_strlen($value);

        return $pad > 0 ? $value.str_repeat(' ', $pad) : $value;
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    private function plural(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
