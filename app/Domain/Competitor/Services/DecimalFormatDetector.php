<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Competitor\Models\CompetitorCsvMapping;

/**
 * Phase 5 Plan 02 Task 1 — decimal-format heuristic (COMP-03 + Pitfall P5-B).
 *
 * Samples the first 10 non-empty values on the price column (skipping the
 * header row), counts regex-matches for comma-as-decimal vs dot-as-decimal,
 * returns majority. Defaults to 'dot' when empty / ambiguous (UK/US is
 * MeetingStore's primary competitor universe).
 *
 * The heuristic is intentionally simple because competitor CSVs are
 * typically single-format within a file; the Filament Reset-mapping UX
 * in Plan 05-04 is the override path if detection drifts.
 *
 * Persisted to competitor_csv_mappings.decimal_format after first-ingest
 * by IngestCompetitorCsvJob so subsequent scrapes skip re-detection.
 */
final class DecimalFormatDetector
{
    private const MAX_SAMPLES = 10;

    /**
     * @param  iterable<int, array<int|string, mixed>>  $sampleRows  raw CSV rows including the header at index 0
     */
    public function detect(iterable $sampleRows, int $priceColIdx): string
    {
        $sample = $this->collectSample($sampleRows, $priceColIdx);

        if ($sample === []) {
            return CompetitorCsvMapping::FORMAT_DOT;
        }

        $commaAsDecimal = 0;
        $dotAsDecimal = 0;

        foreach ($sample as $raw) {
            $value = (string) preg_replace('/[£$€]|GBP|\s/iu', '', $raw);

            // Matches "1.234,56" (EU thousands-dot) or "1234,56" or "56,78"
            if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $value)
                || preg_match('/^\d+,\d{1,2}$/', $value)) {
                $commaAsDecimal++;

                continue;
            }

            // Matches "1,234.56" (UK thousands-comma) or "1234.56" or "56.78"
            if (preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $value)
                || preg_match('/^\d+\.\d{1,2}$/', $value)) {
                $dotAsDecimal++;
            }
        }

        return $commaAsDecimal > $dotAsDecimal
            ? CompetitorCsvMapping::FORMAT_COMMA
            : CompetitorCsvMapping::FORMAT_DOT; // default when tied / empty
    }

    /**
     * @param  iterable<int, array<int|string, mixed>>  $sampleRows
     * @return array<int, string>
     */
    private function collectSample(iterable $sampleRows, int $priceColIdx): array
    {
        $sample = [];
        $i = 0;
        foreach ($sampleRows as $row) {
            if ($i === 0) {
                $i++;

                continue;                                   // skip header
            }
            if (count($sample) >= self::MAX_SAMPLES) {
                break;
            }
            // Row may be indexed by int OR by associative key (spatie/simple-excel
            // returns associative when headers exist). Both paths must work here
            // because DecimalFormatDetector is called before/after header-stripping.
            $raw = null;
            if (is_array($row) && array_key_exists($priceColIdx, $row)) {
                $raw = $row[$priceColIdx];
            } elseif (is_array($row)) {
                $vals = array_values($row);
                $raw = $vals[$priceColIdx] ?? null;
            }
            $raw = trim((string) ($raw ?? ''));
            if ($raw !== '') {
                $sample[] = $raw;
            }
            $i++;
        }

        return $sample;
    }
}
