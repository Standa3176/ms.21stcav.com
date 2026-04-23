<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Exceptions;

/**
 * Phase 6 Plan 02 — thrown by ProductImageProcessor when the input path is
 * unreadable, by ProcessAutoCreateImageJob when storage writes fail, or by
 * callers that want to treat image pipeline failures as terminal errors.
 *
 * ProductImageFetcher itself does NOT throw this — it returns null on all
 * fallback-exhausted paths + logs each attempt to integration_events. The
 * caller (ProcessAutoCreateImageJob) then substitutes the placeholder URL.
 *
 * Decoder failures from intervention/image (malformed bytes) surface as the
 * library's own DecoderException — the Processor lets that propagate so the
 * caller can distinguish "transport failed" (fetcher returned null) from
 * "bytes were garbage" (Processor threw DecoderException).
 */
final class ImageFetchFailedException extends \RuntimeException
{
}
