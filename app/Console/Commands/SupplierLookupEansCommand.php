<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\NormalisesEan;
use App\Domain\Sync\Services\SupplierEanLookup;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260726-sle — supplier:lookup-eans.
 *
 * READ-ONLY: returns the supplier_db feed EAN for an ARBITRARY SKU list,
 * regardless of local product state, and writes a CSV for merging into a review
 * spreadsheet. Solves the gap where products:backfill-merchant-feed --dry-run
 * reports "0 candidate products" for SKUs that already carry a (corrupted) local
 * products.ean — e.g. A30-020 / DS-D6075UN — because its candidate selection
 * excludes already-populated rows.
 *
 * Per SKU it reports: supplier_ean (raw), normalised (via NormalisesEan), whether
 * the normalised value passes the GTIN mod-10 checksum, which pass matched
 * (suppliersku | mpn | none) and found (yes/no).
 *
 *   php artisan supplier:lookup-eans --skus=A30-020,DS-D6075UN
 *   php artisan supplier:lookup-eans --skus-file=storage/app/shortlist-skus.txt --csv=storage/app/supplier-eans.csv
 *
 * No writes anywhere: no supplier_db write, no local products write, no Woo call.
 */
class SupplierLookupEansCommand extends BaseCommand
{
    // Single source of truth for EAN normalisation + checksum (260726-egr).
    use NormalisesEan;

    /** Max rows printed to the console table before capping (CSV always holds all). */
    private const CONSOLE_ROW_CAP = 50;

    protected $signature = 'supplier:lookup-eans
        {--skus= : Comma-separated SKU list}
        {--skus-file= : Path to a file with one SKU per line}
        {--csv= : Output CSV path; writes ALL rows with header sku,supplier_ean,normalised,checksum_valid,matched_by,found}';

    protected $description = 'READ-ONLY: look up the supplier_db EAN for a given SKU list (any local state) and optionally write a CSV.';

    public function __construct(private readonly SupplierEanLookup $lookup)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // Resolve the SKU set from --skus (comma) and/or --skus-file (one per
        // line): union, trim, de-dupe case-insensitively (the supplier lookup is
        // itself LOWER(TRIM())-keyed, so two casings of one SKU are one product).
        // First occurrence keeps its original casing for the output rows.
        /** @var array<string, string> $originalByKey  lower(trim) key => original SKU */
        $originalByKey = [];

        foreach ($this->collectSkus() as $sku) {
            $key = mb_strtolower(trim($sku));
            if ($key === '' || isset($originalByKey[$key])) {
                continue;
            }
            $originalByKey[$key] = trim($sku);
        }

        if ($originalByKey === []) {
            $this->error('No SKUs supplied. Pass --skus=SKU1,SKU2 and/or --skus-file=path/to/skus.txt.');

            return SymfonyCommand::FAILURE;
        }

        $keys = array_keys($originalByKey);
        $this->info('Looking up '.count($keys).' unique SKU(s) against supplier_db (read-only)...');

        $found = $this->lookup->lookup($keys);

        $header = ['sku', 'supplier_ean', 'normalised', 'checksum_valid', 'matched_by', 'found'];

        /** @var array<int, array<int, string>> $rows */
        $rows = [];
        $foundCount = 0;
        $validCount = 0;

        foreach ($originalByKey as $key => $originalSku) {
            $hit = $found[$key] ?? null;
            $raw = $hit !== null ? (string) $hit['ean'] : '';
            $matchedBy = $hit !== null ? (string) $hit['matched_by'] : 'none';
            $isFound = $hit !== null;

            $normalised = $this->normaliseEan($raw) ?? '';
            $checksumValid = ($normalised !== '' && $this->isValidGtinChecksum($normalised));

            if ($isFound) {
                $foundCount++;
            }
            if ($checksumValid) {
                $validCount++;
            }

            $rows[] = [
                $originalSku,
                $raw,
                $normalised,
                $checksumValid ? 'yes' : 'no',
                $matchedBy,
                $isFound ? 'yes' : 'no',
            ];
        }

        $total = count($rows);
        $notFound = $total - $foundCount;

        $this->newLine();
        $this->info("Total: {$total}  Found: {$foundCount}  Checksum-valid: {$validCount}  Not-found: {$notFound}");
        $this->newLine();

        $consoleRows = array_slice($rows, 0, self::CONSOLE_ROW_CAP);
        $this->table($header, $consoleRows);
        if ($total > self::CONSOLE_ROW_CAP) {
            $capped = $total - self::CONSOLE_ROW_CAP;
            $this->warn('Console table capped at '.self::CONSOLE_ROW_CAP." row(s); {$capped} more row(s) omitted"
                .($this->option('csv') ? ' (all rows are in the CSV).' : ' (pass --csv to capture all rows).'));
        }

        $csvPath = (string) $this->option('csv');
        if ($csvPath !== '') {
            $this->writeCsv($csvPath, $header, $rows);
            $this->info("Wrote {$total} row(s) to {$csvPath}");
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Gather raw SKU strings from --skus (comma-separated) and --skus-file
     * (one per line). No trimming/de-duping here — perform() owns that.
     *
     * @return array<int, string>
     */
    private function collectSkus(): array
    {
        $skus = [];

        $inline = (string) $this->option('skus');
        if ($inline !== '') {
            foreach (explode(',', $inline) as $s) {
                $skus[] = $s;
            }
        }

        $file = (string) $this->option('skus-file');
        if ($file !== '') {
            if (! is_file($file)) {
                $this->warn("--skus-file not found: {$file}");
            } else {
                $contents = (string) file_get_contents($file);
                foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                    $skus[] = $line;
                }
            }
        }

        return $skus;
    }

    /**
     * Write ALL rows to CSV (header + one row per input SKU). Creates parent
     * directories if needed. This is the command's OWN output artefact — it is
     * NOT a supplier_db or products write (the READ-ONLY guarantee is about the
     * data sources, not the operator's requested report file).
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $dir = \dirname($path);
        if ($dir !== '' && ! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Could not open CSV path for writing: {$path}");
        }

        try {
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }
    }
}
