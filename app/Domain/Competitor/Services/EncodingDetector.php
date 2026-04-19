<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 5 Plan 02 Task 1 — CSV encoding detector (COMP-03 + Pitfall P5-A).
 *
 * Detection order (authoritative per RESEARCH §2):
 *   1. BOM sniff on the first 3–4 bytes (UTF-8 / UTF-16LE / UTF-16BE)
 *   2. mb_detect_encoding strict against [UTF-8, Windows-1252, ISO-8859-1]
 *   3. Fallback to UTF-8 with a Log::warning('competitor.encoding_detection_ambiguous')
 *
 * Non-UTF-8 sources are converted via mb_convert_encoding to a scratch file
 * under storage/app/competitors/processing/.tmp-{uuid}.csv so
 * spatie/simple-excel always reads a known-good UTF-8 stream.
 *
 * This class is stateless — safe to instantiate anywhere. No constructor
 * dependencies.
 */
final class EncodingDetector
{
    private const SNIFF_BYTES = 4096;

    private const FALLBACK_LIST = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    /**
     * Returns one of: 'UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'.
     *
     * Always returns a string — ambiguous cases log a warning and fall back
     * to 'UTF-8' (P5-A mitigation: never silently guess; always emit a log
     * breadcrumb so ops can tell whether detection was confident).
     */
    public function detect(string $path): string
    {
        $bytes = (string) @file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        // ── Level 1: BOM sniff ── authoritative when present
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        // ── Level 2: mb_detect_encoding with strict=true ──
        $detected = mb_detect_encoding($bytes, self::FALLBACK_LIST, true);
        if ($detected !== false) {
            return $detected;
        }

        // ── Level 3: ambiguous — warn + default UTF-8 ──
        Log::warning('competitor.encoding_detection_ambiguous', [
            'path' => $path,
            'first_bytes_hex' => bin2hex(substr($bytes, 0, 32)),
        ]);

        return 'UTF-8';
    }

    /**
     * Convert $sourcePath (encoded as $detectedEncoding) to a UTF-8 scratch
     * file under storage/app/competitors/processing/.tmp-{uuid}.csv.
     *
     * If the source is already UTF-8, returns $sourcePath unchanged (no
     * unnecessary copy). Callers should always use the returned path for
     * subsequent reading — this method is the single seam that guarantees
     * a UTF-8 stream.
     */
    public function convertToUtf8(string $sourcePath, string $detectedEncoding): string
    {
        if ($detectedEncoding === 'UTF-8') {
            return $sourcePath;
        }

        $raw = (string) @file_get_contents($sourcePath);
        // mb_convert_encoding accepts both the detected encoding string
        // and the target 'UTF-8'. The output is a pure UTF-8 byte stream.
        $utf8 = mb_convert_encoding($raw, 'UTF-8', $detectedEncoding);

        $dir = storage_path('app/competitors/processing');
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $scratch = $dir.DIRECTORY_SEPARATOR.'.tmp-'.(string) Str::uuid().'.csv';
        file_put_contents($scratch, $utf8);

        return $scratch;
    }
}
