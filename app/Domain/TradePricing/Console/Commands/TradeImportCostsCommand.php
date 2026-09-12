<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\TradeCostAdjustment;

/**
 * Quick task 260912-f8x — load a manufacturer price list as trade-only costs.
 *
 * WHY A COMMAND AND NOT THE ADMIN SCREEN
 *
 * The August Yealink platinum list is 120 rows, 70 of which match products we
 * sell. Typing those by hand is how a wrong cost reaches a customer, and a
 * fresh list arrives every month. This reads the file, matches it, shows what
 * it would do, and only writes when told.
 *
 * MATCHING — learned from the 2026-09-10 comparison
 *
 * Midwich prefixes Yealink SKUs with `YEA`, and their `Manu Code` column is
 * the real MPN with an internal id glued on after `**`:
 *
 *     MidwichSku       Manu Code                  Trade
 *     YEAA24           A24**1303161               773
 *     YEAMB65PRO       MB65PRO-A02**1203670       2103
 *
 * So each row is tried three ways against `products.sku`: the manufacturer
 * code (split on `**`), the supplier SKU as given, and the supplier SKU with
 * its brand prefix stripped. That combination matched 70 of 109 by hand; a
 * row that matches nothing is reported, never guessed at.
 *
 * DRY-RUN IS THE DEFAULT. `--apply` writes.
 *
 * CSV only — reading xlsx would mean adding phpoffice/phpspreadsheet for one
 * command. Save the sheet as CSV.
 *
 * Usage:
 *   php artisan trade:import-costs --file=/tmp/yealink-platinum.csv \
 *       --sku-column="Manu Code" --price-column=Trade \
 *       --strip-prefix=YEA --reason="Yealink platinum, August list" \
 *       --valid-until=2026-12-31
 */
final class TradeImportCostsCommand extends BaseCommand
{
    protected $signature = 'trade:import-costs
        {--file= : Path to a CSV with a header row.}
        {--sku-column= : Header naming the SKU / manufacturer code. Default: first of sku, mpn, "manu code", code.}
        {--price-column= : Header naming OUR cost, ex VAT. Default: first of trade, cost, price, net.}
        {--alt-sku-column= : A second header to try when the first does not match (e.g. MidwichSku).}
        {--strip-prefix= : Supplier prefix to try removing, e.g. YEA.}
        {--split-on= : Take the part BEFORE this marker in the SKU column. Defaults to ** (Midwich glues an internal id on after it).}
        {--valid-from= : YYYY-MM-DD. Blank = already in force.}
        {--valid-until= : YYYY-MM-DD. Blank = open-ended. Set it for a dated deal so it lapses on its own.}
        {--reason= : Why, for whoever reads this later.}
        {--apply : Write the rows. WITHOUT THIS NOTHING IS WRITTEN.}';

    protected $description = 'Load a manufacturer price list into trade_cost_adjustments (dry-run by default).';

    /** Midwich glue their internal id onto the manufacturer code after this. */
    private const DEFAULT_SPLIT = '**';

    protected function perform(): int
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_readable($path)) {
            $this->error('--file is required and must be readable.');

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('No data rows found. Does the file have a header row?');

            return self::FAILURE;
        }

        $headers = array_keys($rows[0]);
        $skuCol = $this->resolveColumn('sku-column', $headers, ['sku', 'mpn', 'manu code', 'code', 'part']);
        $priceCol = $this->resolveColumn('price-column', $headers, ['trade', 'cost', 'price', 'net']);
        $altCol = $this->option('alt-sku-column') !== null ? (string) $this->option('alt-sku-column') : null;

        if ($skuCol === null || $priceCol === null) {
            $this->error('Could not identify the SKU and price columns. Pass --sku-column and --price-column.');
            $this->line('  Headers found: '.implode(' | ', $headers));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info(sprintf(
            '%s — %s → SKU from "%s"%s, cost from "%s".',
            $apply ? 'APPLY' : 'DRY-RUN',
            basename($path),
            $skuCol,
            $altCol !== null ? ' (falling back to "'.$altCol.'")' : '',
            $priceCol,
        ));

        // One indexed pass over products beats a query per row.
        $bySku = [];
        foreach (Product::query()->whereNotNull('sku')->get(['id', 'sku']) as $p) {
            $bySku[strtolower(trim((string) $p->sku))] = (string) $p->sku;
        }

        $matched = [];
        $unmatched = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $cost = $this->toMoney($row[$priceCol] ?? null);
            if ($cost === null) {
                $skipped++;

                continue;
            }

            [$sku, $via] = $this->matchSku($row, $skuCol, $altCol, $bySku);

            if ($sku === null) {
                $unmatched[] = trim((string) ($row[$skuCol] ?? '?'));

                continue;
            }

            $matched[$sku] = ['cost' => $cost, 'via' => $via];
        }

        $this->line(sprintf('  %d matched, %d unmatched, %d without a usable price.', count($matched), count($unmatched), $skipped));

        foreach (array_slice($matched, 0, 15, true) as $sku => $m) {
            printf('  %-26s £%10s   (matched on %s)%s', $sku, number_format($m['cost'], 2), $m['via'], PHP_EOL);
        }
        if (count($matched) > 15) {
            $this->line(sprintf('  … and %d more.', count($matched) - 15));
        }

        if ($unmatched !== []) {
            $this->line('');
            $this->warn('Not found in the catalogue (nothing written for these):');
            foreach (array_slice($unmatched, 0, 25) as $u) {
                $this->line('    '.$u);
            }
            if (count($unmatched) > 25) {
                $this->line(sprintf('    … and %d more.', count($unmatched) - 25));
            }
        }

        if (! $apply) {
            $this->line('');
            $this->comment('Nothing was written. Re-run with --apply to load these.');

            return self::SUCCESS;
        }

        // Connection off the model, NOT the DB facade: the facade is fenced as
        // the WpDirectDb layer and TradePricing is denied it (the SYNC-04
        // posture). Same transaction, no boundary break.
        $written = TradeCostAdjustment::query()->getConnection()->transaction(function () use ($matched): int {
            $n = 0;
            foreach ($matched as $sku => $m) {
                // updateOrCreate on the SKU so re-importing next month's list
                // REPLACES the arrangement rather than stacking a second row
                // the resolver would have to arbitrate between.
                TradeCostAdjustment::updateOrCreate(
                    ['sku' => $sku, 'brand_id' => null],
                    [
                        'absolute_cost' => number_format($m['cost'], 4, '.', ''),
                        'adjustment_pct' => null,
                        'valid_from' => $this->option('valid-from') ?: null,
                        'valid_until' => $this->option('valid-until') ?: null,
                        'reason' => (string) ($this->option('reason') ?? 'imported price list'),
                        'is_active' => true,
                    ],
                );
                $n++;
            }

            return $n;
        });

        $this->info(sprintf('APPLY complete — %d arrangement(s) written.', $written));
        $this->comment('Run trade:preview to see the effect, then trade:sync --live to publish.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $bySku
     * @return array{0: ?string, 1: string}
     */
    private function matchSku(array $row, string $skuCol, ?string $altCol, array $bySku): array
    {
        // NOT defaulted in the signature: a trailing `*` there makes Laravel
        // treat the option as an ARRAY, and `--split-on=**` did exactly that.
        $split = trim((string) ($this->option('split-on') ?? ''));
        if ($split === '') {
            $split = self::DEFAULT_SPLIT;
        }
        $prefix = trim((string) ($this->option('strip-prefix') ?? ''));

        $raw = trim((string) ($row[$skuCol] ?? ''));
        $primary = $split !== '' && str_contains($raw, $split)
            ? trim(explode($split, $raw)[0])
            : $raw;

        $alt = $altCol !== null ? trim((string) ($row[$altCol] ?? '')) : '';

        $candidates = [
            [$primary, 'manufacturer code'],
            [$alt, 'supplier sku'],
        ];

        if ($prefix !== '' && $alt !== '' && str_starts_with(strtoupper($alt), strtoupper($prefix))) {
            $candidates[] = [substr($alt, strlen($prefix)), 'supplier sku minus '.$prefix];
        }

        foreach ($candidates as [$candidate, $how]) {
            $key = strtolower(trim((string) $candidate));
            if ($key !== '' && isset($bySku[$key])) {
                return [$bySku[$key], $how];
            }
        }

        return [null, ''];
    }

    /** @param array<int, string> $headers */
    private function resolveColumn(string $option, array $headers, array $guesses): ?string
    {
        $explicit = trim((string) ($this->option($option) ?? ''));
        if ($explicit !== '') {
            foreach ($headers as $h) {
                if (strcasecmp(trim($h), $explicit) === 0) {
                    return $h;
                }
            }

            return null;   // Named a column that is not there — say so, do not guess.
        }

        foreach ($guesses as $guess) {
            foreach ($headers as $h) {
                if (strcasecmp(trim($h), $guess) === 0) {
                    return $h;
                }
            }
        }

        return null;
    }

    /** Money from a spreadsheet cell: strips £, commas and spaces. Null when unusable. */
    private function toMoney(mixed $raw): ?float
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $s = str_replace(['£', ',', ' ', "\u{a0}"], '', $s);
        if (! is_numeric($s)) {
            return null;
        }

        $v = (float) $s;

        return $v > 0 ? $v : null;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        // Strip a UTF-8 BOM from the first header, or every lookup against it fails.
        $headers[0] = preg_replace('/^\x{FEFF}/u', '', (string) $headers[0]) ?? $headers[0];
        $headers = array_map(static fn ($h): string => trim((string) $h), $headers);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim((string) ($line[$i] ?? ''));
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }
}
