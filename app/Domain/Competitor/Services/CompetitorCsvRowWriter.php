<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 Plan 02 Task 2 — single-row writer for the chunk job.
 *
 * One entry point: `write($run, $mapping, $row)`. Routes each CSV row to
 * exactly one of three outcomes:
 *
 *   1. Valid SKU + parseable price + known Product  → competitor_prices
 *      row + CompetitorPriceRecorded event (per-row, ->rows_written++).
 *   2. Valid SKU + parseable price + UNKNOWN Product → OrphanDetector
 *      records suggestion (cross-competitor dedup) + ->rows_orphaned++.
 *   3. Invalid SKU OR unparseable price → CsvParseError row +
 *      ->rows_errored++.
 *
 * COMP-06 seam: gross → ex-VAT conversion goes through Phase 3
 * `PriceCalculator::stripVat` VERBATIM. No local VAT math here — the
 * StripVatReuseTest greps this file to enforce the discipline.
 *
 * COMP-07 dedup: the DB's UNIQUE(competitor_id, sku, recorded_at) index
 * catches the idempotent-re-ingest case. Catch the QueryException, log at
 * info level, and DO NOT increment rows_errored — this is a success path
 * (row already exists), not a parse failure.
 */
final class CompetitorCsvRowWriter
{
    public function __construct(
        private readonly PriceParser $priceParser,
        private readonly OrphanDetector $orphanDetector,
        private readonly PriceCalculator $priceCalculator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $mapping  keys: sku_column_index, price_column_index, decimal_format
     * @param  array<int|string, mixed>  $row
     */
    public function write(CompetitorIngestRun $run, array $mapping, array $row): void
    {
        $skuIdx = (int) $mapping['sku_column_index'];
        $priceIdx = (int) $mapping['price_column_index'];
        $decimalMode = (string) $mapping['decimal_format'];

        $values = array_values($row);     // tolerate both numeric and associative rows
        $sku = trim((string) ($values[$skuIdx] ?? ''));
        $priceRaw = (string) ($values[$priceIdx] ?? '');

        if ($sku === '') {
            $this->writeParseError($run, CsvParseError::TYPE_INVALID_SKU_FORMAT, $row, 'empty SKU');
            $run->increment('rows_errored');

            return;
        }

        $grossPennies = $this->priceParser->toGrossPennies($priceRaw, $decimalMode);
        if ($grossPennies === null) {
            $this->writeParseError($run, CsvParseError::TYPE_UNPARSEABLE_PRICE, $row, 'non-numeric price: '.$priceRaw);
            $run->increment('rows_errored');

            return;
        }

        // Case-insensitive + trim-normalised Product lookup (D-08 orphan-detection precondition)
        $product = Product::whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($sku))])->first();

        if ($product === null) {
            $this->orphanDetector->record($run->competitor, $sku, $grossPennies);
            $run->increment('rows_orphaned');

            return;
        }

        // COMP-06 — reuse Phase 3 stripVat (NEVER duplicate VAT math)
        $exVatPennies = $this->priceCalculator->stripVat($grossPennies, 2000);

        try {
            CompetitorPrice::create([
                'competitor_id' => $run->competitor_id,
                'sku' => $sku,
                'mpn' => null,
                'price_pennies_gross' => $grossPennies,
                'price_pennies_ex_vat' => $exVatPennies,
                'recorded_at' => now(),
                'ingest_run_id' => $run->id,
            ]);
        } catch (QueryException $e) {
            // COMP-07 dedup: UNIQUE(competitor_id, sku, recorded_at) fired — same-second
            // re-ingest of the identical row. This is idempotent + expected; NOT a parse error.
            if ($this->isUniqueViolation($e)) {
                Log::info('competitor.duplicate_row_skipped', [
                    'competitor_id' => $run->competitor_id,
                    'sku' => $sku,
                    'ingest_run_id' => $run->id,
                ]);

                return;
            }
            throw $e;
        }

        event(new CompetitorPriceRecorded(
            competitorId: (int) $run->competitor_id,
            sku: $sku,
            priceGrossPennies: $grossPennies,
            priceExVatPennies: $exVatPennies,
            ingestRunId: (int) $run->id,
        ));

        $run->increment('rows_written');
    }

    /**
     * @param  array<int|string, mixed>  $row
     */
    private function writeParseError(
        CompetitorIngestRun $run,
        string $issueType,
        array $row,
        string $detail,
    ): void {
        CsvParseError::create([
            'ingest_run_id' => $run->id,
            'competitor_id' => $run->competitor_id,
            'filename' => (string) $run->filename,
            'issue_type' => $issueType,
            'line_number' => null,
            'raw_line' => implode(',', array_map(fn ($v) => (string) $v, array_values($row))),
            'context' => ['detail' => $detail],
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // MySQL: SQLSTATE 23000; errno 1062 for duplicate entry.
        return str_contains($e->getMessage(), '1062')
            || str_contains($e->getMessage(), 'Duplicate entry')
            || ($e->errorInfo[0] ?? '') === '23000';
    }
}
