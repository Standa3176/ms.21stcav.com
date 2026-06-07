<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/**
 * Shared EAN/GTIN normaliser — single source of truth.
 *
 * Extracted from GenerateProductDraftsCommand 2026-06-07 (quick task 260607-cgd)
 * so the new products:backfill-merchant-feed command consumes the BYTE-IDENTICAL
 * validator. Same drift-prevention pattern as 260606-o63 Product::scopeAutoCreated.
 *
 * Behaviour (preserved verbatim from the original private method, lines 479-491):
 *   - Trim/strip everything but digits via preg_replace('/\D+/', ...).
 *   - Require length 8..14 (covers GTIN-8 / UPC-12 / EAN-13 / GTIN-14).
 *   - Reject all-zero / all-nine placeholders (common feed sentinels).
 *   - Return null for blanks / placeholders / anything that doesn't look real.
 *
 * Tested in tests/Unit/Console/Concerns/NormalisesEanTest.php across 15 cases.
 * Drift gate (260607-cgd Task 1 verify step):
 *   grep -n "private function normaliseEan" app/Console/Commands/ → expect 0 hits.
 */
trait NormalisesEan
{
    /**
     * Normalise an EAN/GTIN from the supplier feed: trim, strip spaces/hyphens,
     * keep digits only; require a plausible length (8-14, covering GTIN-8/UPC-12/
     * EAN-13/GTIN-14). Returns null for blanks, placeholders (all-zero, all-nine),
     * and anything that doesn't look like a real barcode.
     */
    public function normaliseEan(mixed $raw): ?string
    {
        $s = preg_replace('/\D+/', '', (string) ($raw ?? '')) ?? '';
        $len = strlen($s);
        if ($len < 8 || $len > 14) {
            return null;
        }
        // Reject all-zero / all-nine placeholders (common feed sentinels).
        if (preg_match('/^(0+|9+)$/', $s) === 1) {
            return null;
        }

        return $s;
    }
}
