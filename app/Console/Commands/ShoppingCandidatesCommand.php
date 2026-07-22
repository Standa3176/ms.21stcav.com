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
        {--min-margin-pence=19900 : Minimum (sell - buy) margin in pence}
        {--min-competitors=2 : Minimum DISTINCT competitors currently listing the SKU}
        {--competitor-window-days=30 : How recent a competitor price must be to count}
        {--allow-missing-gtin : Keep (and flag) products with no EAN — Google will likely disapprove them}
        {--sort=score : score|margin|competitors (score = competitor_count x margin_pence)}
        {--limit=200 : Shortlist size}
        {--preview=25 : How many shortlist rows to print to the console}
        {--live-stock : Additionally confirm each shortlisted SKU against the LIVE fresh-supplier feed}
        {--csv= : Write the full shortlist to this CSV path}';

    protected $description = 'Rank Google-Merchant-eligible, high-margin, competitor-validated products for a Google Shopping trial (read-only).';

    /** CSV header — mirrored by the command test. */
    private const CSV_HEADER = [
        'rank', 'sku', 'name', 'brand', 'brand_id', 'woo_product_id', 'ean', 'has_gtin',
        'buy_price_pence', 'sell_price_pence', 'margin_pence', 'margin_pct',
        'competitor_count', 'lowest_competitor_gross_pence', 'position',
        'delta_vs_lowest_pence', 'stock', 'supplier_name', 'score',
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

        $liveDropped = 0;
        if ((bool) $this->option('live-stock') && $rows !== []) {
            [$rows, $liveDropped] = $this->confirmAgainstLiveStock($rows);
        }

        $this->renderFunnel($funnel, $minMarginPence, $minCompetitors, $windowDays, $allowMissingGtin);

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
                $this->truncate((string) $row['name'], 38),
                $this->truncate((string) ($row['brand'] ?? '—'), 16),
                $this->pounds((int) $row['margin_pence']),
                sprintf('%.1f%%', ((int) $row['margin_pct_bps']) / 100),
                (int) $row['competitor_count'],
                $this->pounds((int) $row['lowest_comp_pence']),
                $row['position'].' '.$this->pounds(abs((int) $row['delta_vs_lowest_pence'])),
                (int) $row['stock'],
                ((bool) $row['has_gtin']) ? 'yes' : 'NO',
                (int) $row['score'],
            ];
        }

        $this->table(
            ['#', 'SKU', 'Name', 'Brand', 'Margin', 'Margin%', 'Comps', 'Lowest', 'Vs lowest', 'Stock', 'GTIN', 'Score'],
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
                $row['sell_price_pence'],
                $row['margin_pence'],
                sprintf('%.2f', ((int) $row['margin_pct_bps']) / 100),
                $row['competitor_count'],
                $row['lowest_comp_pence'],
                $row['position'],
                $row['delta_vs_lowest_pence'],
                $row['stock'],
                $row['supplier_name'] ?? '',
                $row['score'],
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
        $this->warn('  Ranking uses COMPETITOR BREADTH as a DEMAND PROXY — not UK search volume.');
        $this->line('  This app holds no UK market volume data (last_sales_count_90d and GA4 are');
        $this->line('  own-site signals). "N competitors currently list this SKU" is evidence that');
        $this->line('  the product sells somewhere, not evidence of how much it is searched for.');
        $this->line('  Validate true demand in Google Keyword Planner (location: United Kingdom)');
        $this->line('  on the exported SKU/name list before committing Shopping spend.');
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
