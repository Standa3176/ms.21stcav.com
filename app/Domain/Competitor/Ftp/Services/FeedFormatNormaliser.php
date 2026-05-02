<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Services;

use App\Domain\Competitor\Ftp\Exceptions\FormatNormalisationException;
use App\Domain\Competitor\Services\EncodingDetector;
use ZipArchive;

/**
 * Phase 11.2 Plan 01 — D-05 + D-06 + D-07: format normaliser.
 *
 * Converts remote feed files (csv/tsv/zip/txt) to canonical CSV BEFORE they
 * land in storage/app/competitors/incoming/. Phase 5 watcher only ever sees
 * .csv files (D-07 invariant — Phase 5 ingest pipeline byte-identical).
 *
 * Format paths (D-05):
 *   - csv  → passthrough (return $tempPath unchanged)
 *   - tsv  → fgetcsv with "\t" delimiter → fputcsv default ",". Reuses Phase 5
 *            EncodingDetector for BOM / Windows-1252 / UTF-8 handling.
 *   - zip  → ZipArchive::extractTo first matching *.csv (case-insensitive); throws
 *            FormatNormalisationException if no CSV is present. T-11.2-08
 *            mitigation: extractTo with explicit single-entry allow-list rejects
 *            zip-slip / path traversal at the library layer.
 *   - txt  → sniff first ~100 lines; if tab/comma/semicolon hits 80% of all
 *            delimiter chars, convert to CSV; otherwise throw.
 *
 * Output is always an absolute path to a `.csv` file in sys_get_temp_dir() —
 * caller atomically `rename()`s into incoming/.
 *
 * Stateless service; safe to instantiate per-feed.
 */
class FeedFormatNormaliser
{
    public function __construct(private readonly EncodingDetector $encodingDetector) {}

    /**
     * Normalise the temp file at $tempPath to canonical CSV.
     *
     * @return string Absolute path to the normalised .csv file.
     *
     * @throws FormatNormalisationException
     */
    public function normalise(string $tempPath, string $format, string $localFilename): string
    {
        return match ($format) {
            'csv' => $tempPath, // D-05: passthrough
            'tsv' => $this->normaliseTsv($tempPath, $localFilename),
            'zip' => $this->normaliseZip($tempPath, $localFilename),
            'txt' => $this->normaliseTxt($tempPath, $localFilename),
            default => throw FormatNormalisationException::unsupportedFormat($format),
        };
    }

    private function normaliseTsv(string $tempPath, string $localFilename): string
    {
        // Phase 5 EncodingDetector reuse — handles BOM + Windows-1252 + UTF-8.
        $encoded = $this->encodingDetector->detect($tempPath);
        $sourcePath = $this->encodingDetector->convertToUtf8($tempPath, $encoded);

        $outPath = $this->csvScratchPath();

        $in = fopen($sourcePath, 'r');
        $out = fopen($outPath, 'w');

        // Strip UTF-8 BOM if present at start of first line.
        $first = fgets($in);
        if ($first !== false && substr($first, 0, 3) === "\xEF\xBB\xBF") {
            $first = substr($first, 3);
        }
        if ($first !== false) {
            $row = str_getcsv(rtrim($first, "\r\n"), "\t");
            fputcsv($out, $row);
        }

        while (($row = fgetcsv($in, 0, "\t")) !== false) {
            fputcsv($out, $row);
        }

        fclose($in);
        fclose($out);

        return $outPath;
    }

    private function normaliseZip(string $tempPath, string $localFilename): string
    {
        $zip = new ZipArchive();
        if ($zip->open($tempPath) !== true) {
            throw FormatNormalisationException::zipOpenFailed($localFilename);
        }

        // Locate first *.csv entry (case-insensitive) — deterministic by archive order.
        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (preg_match('/\.csv$/i', $name) === 1) {
                $csvName = $name;
                break;
            }
        }
        if ($csvName === null) {
            $zip->close();
            throw FormatNormalisationException::noCsvInZip($localFilename);
        }

        // T-11.2-08 mitigation: extractTo with explicit single-entry allow-list.
        $extractTo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'feed_norm_'.uniqid('', true);
        if (! is_dir($extractTo) && ! @mkdir($extractTo, 0o775, true)) {
            $zip->close();
            throw new FormatNormalisationException("FeedFormatNormaliser: failed to create extraction dir for {$localFilename}");
        }

        $zip->extractTo($extractTo, [$csvName]);
        $zip->close();

        $extracted = $extractTo.DIRECTORY_SEPARATOR.$csvName;
        if (! is_file($extracted)) {
            throw new FormatNormalisationException(
                "FeedFormatNormaliser: zip extraction silently failed for entry {$csvName} (feed={$localFilename})"
            );
        }

        return $extracted;
    }

    private function normaliseTxt(string $tempPath, string $localFilename): string
    {
        // Sniff first ~100 lines for delimiter pattern (tab / comma / semicolon).
        $handle = fopen($tempPath, 'r');
        $sample = '';
        $lines = 0;
        while (! feof($handle) && $lines < 100) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sample .= $line;
            $lines++;
        }
        fclose($handle);

        $tabs = substr_count($sample, "\t");
        $commas = substr_count($sample, ',');
        $semis = substr_count($sample, ';');
        $total = $tabs + $commas + $semis;

        if ($total === 0) {
            throw FormatNormalisationException::delimiterUncertain($localFilename);
        }

        $max = max($tabs, $commas, $semis);
        $confidence = $max / $total;
        if ($confidence < 0.80) {
            throw FormatNormalisationException::delimiterUncertain($localFilename);
        }

        $delim = $tabs === $max ? "\t" : ($semis === $max ? ';' : ',');

        if ($delim === ',') {
            // Already comma-separated — copy with .csv extension.
            $outPath = $this->csvScratchPath();
            copy($tempPath, $outPath);

            return $outPath;
        }

        $outPath = $this->csvScratchPath();
        $in = fopen($tempPath, 'r');
        $out = fopen($outPath, 'w');
        while (($row = fgetcsv($in, 0, $delim)) !== false) {
            fputcsv($out, $row);
        }
        fclose($in);
        fclose($out);

        return $outPath;
    }

    private function csvScratchPath(): string
    {
        // tempnam returns a unique zero-byte path; append `.csv` extension by
        // creating a sibling file (tempnam doesn't accept a suffix). Caller
        // overwrites the contents via fopen('w').
        $base = tempnam(sys_get_temp_dir(), 'feed_norm_');
        $csvPath = $base.'.csv';
        @unlink($base); // release the placeholder; we use the .csv suffix file.

        return $csvPath;
    }
}
