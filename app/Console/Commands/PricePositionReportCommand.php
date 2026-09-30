<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\PricePositionClassifier;
use App\Domain\Products\Models\Product;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260930-eo3 — price-position report (READ-ONLY).
 *
 * Takes a list of manufacturer part numbers and produces a PDF marking, per
 * product: where we sit against the tracked resellers, and whether our live
 * price is under our own margin floor or under cost outright.
 *
 * ── Read-only contract ──
 * SELECT only. No DB write, no Woo REST call, no Google call, no price change.
 * The only file written is the PDF (and optional CSV) the operator asks for.
 *
 * ── Why DOMPDF directly, not the Pdf facade ──
 * `QuotePdfRenderer` uses spatie/laravel-pdf, whose driver defaults to
 * `browsershot` — Node + Puppeteer + a headless Chrome. This command has to run
 * on the VPS from an SSH session, so it uses DOMPDF (pure PHP, already a
 * dependency) directly and cannot be broken by a missing Node install or a
 * LARAVEL_PDF_DRIVER that happens to point at Chrome. The trade is that the
 * Blade template must stay to simple table CSS, which it does.
 *
 * ── Matching ──
 * The input holds MANUFACTURER part numbers; `products.sku` is ours and may
 * differ. Each row is resolved in three passes — exact SKU, then a normalised
 * SKU (case-folded, spaces/hyphens/dots/hashes stripped), then normalised
 * against the competitor feed's own sku/mpn — and the pass that matched is
 * printed. A row that matches nothing is reported as a CATALOGUE GAP, which is
 * a different finding from a pricing verdict and must not be confused with one.
 *
 * ── Example (prod) ──
 *   php artisan products:price-position-report \
 *     --pdf=storage/app/research/run-adds-price-position.pdf
 */
final class PricePositionReportCommand extends BaseCommand
{
    protected $signature = 'products:price-position-report
        {--list=resources/reports/run-adds-part-numbers.tsv : TSV of name<TAB>part-number; # comments allowed}
        {--window-days=30 : How recent a competitor price must be to count}
        {--pdf=storage/app/research/price-position.pdf : Where to write the PDF}
        {--csv= : Also write the same rows as CSV}
        {--title=Ad shortlist — price position : Heading printed on the PDF}';

    protected $description = 'Mark a list of part numbers as best-priced / could-be-best / below cost or floor, and render a PDF (read-only).';

    protected function perform(): int
    {
        $listPath = $this->resolvePath((string) $this->option('list'));
        if (! File::exists($listPath)) {
            $this->error("List not found: {$listPath}");

            return SymfonyCommand::FAILURE;
        }

        $windowDays = max(1, (int) $this->option('window-days'));
        $wanted = $this->readList($listPath);

        if ($wanted === []) {
            $this->error('The list is empty.');

            return SymfonyCommand::FAILURE;
        }

        $this->newLine();
        $this->line('── products:price-position-report (READ-ONLY) ──');
        $this->line(sprintf('  %d part number(s) · competitor window %dd', count($wanted), $windowDays));

        $products = $this->loadProducts();
        $competitors = $this->lowestCompetitorByKey($windowDays);
        $classifier = app(PricePositionClassifier::class);

        $rows = [];
        foreach ($wanted as [$name, $partNumber]) {
            $rows[] = $this->buildRow($name, $partNumber, $products, $competitors, $classifier);
        }

        $this->renderSummary($rows);
        $this->renderTable($rows);

        $pdfPath = $this->writePdf($rows, $windowDays);
        $this->newLine();
        $this->info("PDF written: {$pdfPath}");

        if ($this->option('csv') !== null && trim((string) $this->option('csv')) !== '') {
            $csvPath = $this->writeCsv($rows);
            $this->info("CSV written: {$csvPath}");
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function readList(string $path): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) File::get($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\t+/', $line) ?: [];
            if (count($parts) < 2) {
                // Tolerate a bare part-number list — the name is cosmetic.
                $out[] = [$line, $line];

                continue;
            }
            $out[] = [trim($parts[0]), trim($parts[1])];
        }

        return $out;
    }

    /**
     * Normalise an identifier for fuzzy matching. Manufacturer part numbers are
     * written inconsistently across a spreadsheet, a supplier feed and our own
     * catalogue — "MVC S40-C5U-000", "MVCS40-C5U-000" and "mvc-s40-c5u-000" are
     * the same product. Case, spaces, hyphens, dots, slashes and the '#ABU'
     * style suffix separators are all noise for matching purposes.
     */
    public static function normaliseKey(string $raw): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($raw)));
    }

    /**
     * Our catalogue, indexed by exact and normalised SKU.
     *
     * @return array{exact: array<string, object>, normal: array<string, object>}
     */
    private function loadProducts(): array
    {
        $exact = [];
        $normal = [];

        Product::query()
            ->select(['id', 'sku', 'name', 'status', 'type', 'buy_price', 'sell_price', 'ean', 'stock_status'])
            ->orderBy('id')
            ->chunkById(1000, function ($chunk) use (&$exact, &$normal): void {
                foreach ($chunk as $p) {
                    $sku = trim((string) $p->sku);
                    if ($sku === '') {
                        continue;
                    }
                    $exact[strtolower($sku)] = $p;
                    $key = self::normaliseKey($sku);
                    // First writer wins: a later duplicate must not silently
                    // replace the row we already reported against.
                    if ($key !== '' && ! isset($normal[$key])) {
                        $normal[$key] = $p;
                    }
                }
            });

        return ['exact' => $exact, 'normal' => $normal];
    }

    /**
     * Normalised match key → lowest CURRENT competitor gross pennies + how many
     * distinct competitors listed it.
     *
     * "Current" = the latest row per (competitor, sku) inside the window, the
     * same windowed reduction the ad scanners use, so a competitor with a year
     * of daily rows counts once rather than 365 times.
     *
     * @return array<string, array{lowest: int, competitors: int}>
     */
    private function lowestCompetitorByKey(int $windowDays): array
    {
        $cutoff = now()->subDays($windowDays)->toDateTimeString();

        $rows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_gross FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_gross, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices WHERE recorded_at >= ? AND price_pennies_gross > 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        /** @var array<string, array{lowest: int, ids: array<int, true>}> $acc */
        $acc = [];
        foreach ($rows as $r) {
            $price = (int) $r->price_pennies_gross;
            if ($price <= 0) {
                continue;
            }
            $competitorId = (int) $r->competitor_id;
            foreach ([(string) $r->sku, (string) ($r->mpn ?? '')] as $raw) {
                $k = self::normaliseKey($raw);
                if ($k === '') {
                    continue;
                }
                if (! isset($acc[$k])) {
                    $acc[$k] = ['lowest' => $price, 'ids' => []];
                }
                $acc[$k]['lowest'] = min($acc[$k]['lowest'], $price);
                $acc[$k]['ids'][$competitorId] = true;
            }
        }

        $out = [];
        foreach ($acc as $k => $v) {
            $out[$k] = ['lowest' => $v['lowest'], 'competitors' => count($v['ids'])];
        }

        return $out;
    }

    /**
     * @param  array{exact: array<string, object>, normal: array<string, object>}  $products
     * @param  array<string, array{lowest: int, competitors: int}>  $competitors
     * @return array<string, mixed>
     */
    private function buildRow(
        string $name,
        string $partNumber,
        array $products,
        array $competitors,
        PricePositionClassifier $classifier,
    ): array {
        $key = self::normaliseKey($partNumber);

        $product = $products['exact'][strtolower(trim($partNumber))] ?? null;
        $match = $product !== null ? 'exact' : null;

        if ($product === null && $key !== '') {
            $product = $products['normal'][$key] ?? null;
            $match = $product !== null ? 'normalised' : null;
        }

        $comp = $competitors[$key] ?? null;

        if ($product === null) {
            // A catalogue gap, NOT a pricing verdict. Say whether competitors
            // carry it, because "they stock it and we do not" is the actionable
            // version of this row.
            return [
                'name' => $name,
                'part_number' => $partNumber,
                'match' => 'NOT FOUND',
                'sku' => null,
                'status' => 'not_in_catalogue',
                'label' => $comp !== null ? 'Not in catalogue (competitors list it)' : 'Not in catalogue',
                'tone' => 'muted',
                'sell_gross_pence' => null,
                'buy_pence' => null,
                'net_sell_pence' => null,
                'true_margin_pence' => null,
                'floor_gross_pence' => null,
                'lowest_comp_pence' => $comp['lowest'] ?? null,
                'competitors' => $comp['competitors'] ?? 0,
                'delta_vs_lowest_pence' => null,
                'undercut_target_pence' => null,
                'headroom_pence' => null,
                'stock_status' => null,
                'published' => false,
            ];
        }

        $sellGross = (int) round(((float) $product->sell_price) * 100);
        $buy = (int) round(((float) $product->buy_price) * 100);

        $verdict = $classifier->classify($sellGross, $buy, $comp['lowest'] ?? null);
        $describe = PricePositionClassifier::describe($verdict['status']);

        return [
            'name' => $name,
            'part_number' => $partNumber,
            'match' => $match,
            'sku' => (string) $product->sku,
            'status' => $verdict['status'],
            'label' => $describe['label'],
            'tone' => $describe['tone'],
            'sell_gross_pence' => $sellGross,
            'buy_pence' => $buy,
            'net_sell_pence' => $verdict['net_sell_pence'],
            'true_margin_pence' => $verdict['true_margin_pence'],
            'floor_gross_pence' => $verdict['floor_gross_pence'],
            'lowest_comp_pence' => $comp['lowest'] ?? null,
            'competitors' => $comp['competitors'] ?? 0,
            'delta_vs_lowest_pence' => $verdict['delta_vs_lowest_pence'],
            'undercut_target_pence' => $verdict['undercut_target_pence'],
            'headroom_pence' => $verdict['headroom_pence'],
            'stock_status' => (string) ($product->stock_status ?? ''),
            'published' => (string) $product->status === 'publish',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSummary(array $rows): void
    {
        $counts = [];
        foreach ($rows as $r) {
            $counts[(string) $r['status']] = ($counts[(string) $r['status']] ?? 0) + 1;
        }
        arsort($counts);

        $this->newLine();
        $this->line('── Summary ──────────────────────────────────────────');
        foreach ($counts as $status => $n) {
            $label = $status === 'not_in_catalogue'
                ? 'Not in catalogue'
                : PricePositionClassifier::describe($status)['label'];
            $this->line(sprintf('    %-32s %4d', $label, $n));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderTable(array $rows): void
    {
        $table = [];
        foreach ($rows as $r) {
            $table[] = [
                $this->truncate((string) $r['name'], 34),
                (string) $r['part_number'],
                $r['label'],
                $r['sell_gross_pence'] !== null ? $this->pounds((int) $r['sell_gross_pence']) : '—',
                $r['lowest_comp_pence'] !== null ? $this->pounds((int) $r['lowest_comp_pence']) : '—',
                $r['floor_gross_pence'] !== null ? $this->pounds((int) $r['floor_gross_pence']) : '—',
                $r['true_margin_pence'] !== null ? $this->pounds((int) $r['true_margin_pence']) : '—',
                (int) $r['competitors'],
            ];
        }

        $this->newLine();
        $this->table(
            ['Product', 'Part number', 'Verdict', 'Our price', 'Lowest comp', 'Floor', 'True margin', 'Comps'],
            $table,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writePdf(array $rows, int $windowDays): string
    {
        $html = View::make('pdf.price-position', [
            'rows' => $rows,
            'title' => (string) $this->option('title'),
            'windowDays' => $windowDays,
            'floorBps' => (int) config('competitor.min_margin_floor_bps', 600),
            'beatBy' => (int) config('competitor.beat_by_pennies', 1),
            'generatedAt' => now(),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);   // no network fetches from a report
        $options->set('defaultFont', 'DejaVu Sans'); // ships with DOMPDF; has £ and —

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        $path = $this->resolvePath((string) $this->option('pdf'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) $dompdf->output());

        return $path;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = $this->resolvePath((string) $this->option('csv'));
        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV for writing: {$path}");
        }

        fputcsv($handle, [
            'name', 'part_number', 'matched_by', 'our_sku', 'verdict', 'published', 'stock_status',
            'our_price_gross_pence', 'buy_price_pence', 'net_sell_pence', 'true_margin_pence',
            'floor_gross_pence', 'headroom_vs_floor_pence',
            'lowest_competitor_gross_pence', 'competitors', 'delta_vs_lowest_pence',
            'undercut_target_pence',
        ]);

        foreach ($rows as $r) {
            fputcsv($handle, [
                $r['name'], $r['part_number'], $r['match'] ?? '', $r['sku'] ?? '', $r['status'],
                $r['published'] ? 'yes' : 'no', $r['stock_status'] ?? '',
                $r['sell_gross_pence'] ?? '', $r['buy_pence'] ?? '', $r['net_sell_pence'] ?? '',
                $r['true_margin_pence'] ?? '', $r['floor_gross_pence'] ?? '', $r['headroom_pence'] ?? '',
                $r['lowest_comp_pence'] ?? '', $r['competitors'], $r['delta_vs_lowest_pence'] ?? '',
                $r['undercut_target_pence'] ?? '',
            ]);
        }
        fclose($handle);

        return $path;
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
        return ($pence < 0 ? '-£' : '£').number_format(abs($pence) / 100, 2);
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
