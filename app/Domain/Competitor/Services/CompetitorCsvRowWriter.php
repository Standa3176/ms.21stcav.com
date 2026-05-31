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
    ) {}

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

        // 2026-05-31 — silently skip two classes of noise BEFORE the SKU-fallback
        // heuristic below (which would otherwise rescue a fake-SKU from the id
        // column for these rows and then log them as "non-numeric price" errors,
        // which is exactly the 1,065-error Onedirect pile-up we just cleaned up):
        //
        //   a) DUPLICATE HEADER ROWS — competitor exports that append on each run
        //      (Onedirect ships SKU,Price,Name,id,createdAt,updatedAt fresh in
        //      every export, accumulating headers throughout the file).
        //   b) INFORMATIONAL NAME-ONLY ROWS — both SKU and Price columns empty
        //      (Onedirect lists out-of-stock / EOL products with just a name +
        //      internal id + timestamps; no actionable competitor price data).
        //
        // Returning here writes nothing + logs nothing — neither rows_written
        // nor rows_errored is incremented, matching the existing semantics of
        // "a row that holds no data".
        $priceTrim = trim($priceRaw);
        $looksLikeRepeatedHeader = strcasecmp($sku, 'sku') === 0
            && strcasecmp($priceTrim, 'price') === 0;
        $hasNoActionableData = $sku === '' && $priceTrim === '';
        if ($looksLikeRepeatedHeader || $hasNoActionableData) {
            return;
        }

        // Quick task 260504-edk — when the configured SKU column is empty, fall
        // back to the first non-empty short alphanumeric token elsewhere in the
        // row (skipping the SKU + price columns). Observed against onedirect:
        // products with no manufacturer SKU at col 0 still ship a real internal
        // id at col 3. The fallback value persists as competitor_prices.sku and
        // shows as Orphan unless it happens to match a Product.
        if ($sku === '') {
            foreach ($values as $i => $cell) {
                if ($i === $skuIdx || $i === $priceIdx) {
                    continue;
                }
                $candidate = trim((string) $cell);
                if ($candidate === '' || strlen($candidate) > 64) {
                    continue;
                }
                if (preg_match('/^[A-Za-z0-9._\-\/]+$/', $candidate) === 1) {
                    $sku = $candidate;
                    break;
                }
            }
        }

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

        // Case-insensitive + trim-normalised Product lookup.
        // Match status determines which downstream signals fire (orphan detector,
        // margin-analyser event), but the price row itself persists regardless —
        // ops need to see ALL competitor pricing data, not just rows that happen
        // to match a Product (early-adoption Products table is near-empty).
        // Quick task 260504-01s flipped this from "drop orphan rows on the floor"
        // to "persist orphans, suppress matched-only events."
        $product = Product::whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($sku))])->first();
        $isMatched = $product !== null;

        if (! $isMatched) {
            // Orphan side-effects: record suggestion (cross-competitor dedup) +
            // increment rows_orphaned audit metric. These were the original
            // orphan-path side-effects; preserved unchanged.
            $this->orphanDetector->record($run->competitor, $sku, $grossPennies);
            $run->increment('rows_orphaned');
        }

        // Competitor feeds are EX-VAT (net/trade) prices — operator-confirmed
        // 2026-05-24. The raw parsed value ($grossPennies) is therefore the
        // EX-VAT price; the VAT-inclusive gross is derived by adding VAT.
        // COMP-06 — reuse Phase 3 VAT math (NEVER duplicate it here).
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $exVatPennies = $grossPennies;
        $grossInclPennies = $this->priceCalculator->addVat($grossPennies, $vatBps);

        try {
            CompetitorPrice::create([
                'competitor_id' => $run->competitor_id,
                'sku' => $sku,
                'mpn' => null,
                'price_pennies_gross' => $grossInclPennies,
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

        // Margin-analyser event ONLY fires for matched rows — downstream listeners
        // need a Product to compute margin/cost deltas. Orphans persist as price
        // history but skip the analytics fan-out.
        if ($isMatched) {
            event(new CompetitorPriceRecorded(
                competitorId: (int) $run->competitor_id,
                sku: $sku,
                priceGrossPennies: $grossPennies,
                priceExVatPennies: $exVatPennies,
                ingestRunId: (int) $run->id,
            ));
        }

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
