<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Exceptions;

use RuntimeException;

/**
 * Phase 11.2 Plan 01 — D-05 normaliser failure surface.
 *
 * Thrown by FeedFormatNormaliser when a source feed cannot be converted to
 * canonical CSV (corrupt zip, txt with no clear delimiter, unsupported format
 * enum). Caller (CompetitorFtpPullCommand) catches → increments
 * consecutive_failures + writes csv_parse_errors row → no malformed file
 * lands in storage/app/competitors/incoming/ (T-11.2-12 mitigation).
 */
class FormatNormalisationException extends RuntimeException
{
    public static function noCsvInZip(string $localFilename): self
    {
        return new self(
            "FeedFormatNormaliser: no CSV found inside zip archive for feed local_filename={$localFilename}"
        );
    }

    public static function delimiterUncertain(string $localFilename): self
    {
        return new self(
            "FeedFormatNormaliser: could not detect delimiter in txt file for feed local_filename={$localFilename}"
        );
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(
            "FeedFormatNormaliser: unsupported format '{$format}'; expected one of: csv, tsv, zip, txt"
        );
    }

    public static function zipOpenFailed(string $localFilename): self
    {
        return new self(
            "FeedFormatNormaliser: failed to open zip archive for feed local_filename={$localFilename}"
        );
    }
}
