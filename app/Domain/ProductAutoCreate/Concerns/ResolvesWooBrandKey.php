<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Concerns;

/**
 * Quick task 260702-h50 — shared feed-manufacturer → Woo-brand resolution.
 *
 * Extracted VERBATIM from DraftFromSuggestionsCommand so that command and the
 * new RefreshBrandsToAddCommand share ONE implementation of the brand-key
 * resolution rules (260628-b9t exact + " - <suffix>" strip; 260629-rct
 * multi-manufacturer first-resolvable pick). Behaviour is byte-identical to
 * the pre-extraction inline methods — DraftFromSuggestionsBrandMatchTest +
 * DraftFromSuggestionsMultiMfrTest continue to pass unchanged.
 */
trait ResolvesWooBrandKey
{
    /**
     * Resolve a feed manufacturer string to a Woo brand KEY (lowercased), or null.
     *
     * Feed manufacturers are frequently "Brand - Category" shaped (e.g.
     * "Yealink - Headset"), which never equals the clean "Yealink" brand term.
     * Strategy: exact match first (preserves all current behaviour); on miss,
     * strip a trailing " - <suffix>" segment and retry. Conservative — only the
     * " - " (space-hyphen-space) separator is treated as a category suffix.
     *
     * @param  array<string,string>  $wooBrandsByLower  lowercased-name => canonical-name
     */
    public function resolveBrandKey(string $mfrLower, array $wooBrandsByLower): ?string
    {
        $mfrLower = trim($mfrLower);
        if ($mfrLower === '') {
            return null;
        }

        // 1. Exact (current behaviour).
        if (isset($wooBrandsByLower[$mfrLower])) {
            return $mfrLower;
        }

        // 2. Strip a trailing " - <suffix>": take the segment before the FIRST
        //    " - " so "yealink - headset - uk" → "yealink". Retry.
        if (str_contains($mfrLower, ' - ')) {
            $lead = trim(explode(' - ', $mfrLower, 2)[0]);
            if ($lead !== '' && isset($wooBrandsByLower[$lead])) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * Pick the first manufacturer that resolves to a Woo brand.
     * Returns [brandKey, matchedManufacturer]; [null, null] if none resolve.
     * Handles the multi-row case (e.g. a product + a warranty/protection-plan row
     * sharing the same MPN) — prefer the real brand over a non-brand add-on label.
     *
     * @param  array<int,string>  $manufacturers
     * @param  array<string,string>  $wooBrandsByLower
     * @return array{0:?string,1:?string}
     */
    public function firstResolvableBrandKey(array $manufacturers, array $wooBrandsByLower): array
    {
        foreach ($manufacturers as $mfr) {
            $bk = $this->resolveBrandKey(mb_strtolower(trim((string) $mfr)), $wooBrandsByLower);
            if ($bk !== null) {
                return [$bk, $mfr];
            }
        }

        return [null, null];
    }
}
