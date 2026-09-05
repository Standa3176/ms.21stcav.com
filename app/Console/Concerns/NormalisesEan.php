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

    /**
     * Validate the GTIN mod-10 check digit (quick task 260726-egr).
     *
     * The MISSING gate: normaliseEan() above length-checks and rejects all-zero/
     * all-nine sentinels, but does NO check-digit validation — so precision-
     * mangled values that happen to be the right length passed as "valid" (e.g.
     * A30-020's corrupted local `6938820000000`, which fails this check while
     * Woo held the real GTIN `0841885115294`). This method is the reverse
     * source-of-truth guard for products:reconcile-ean-from-woo.
     *
     * Standard GTIN mod-10: the rightmost digit is the check digit; working
     * right-to-left from the digit immediately left of it, weights alternate
     * 3,1,3,1…; the check digit is (10 − sum % 10) % 10. Valid for the real
     * GTIN lengths only — GTIN-8 / UPC-12 / EAN-13 / GTIN-14. Anything with a
     * non-standard length or a non-digit character is rejected regardless of
     * whether mod-10 would coincidentally pass.
     *
     * ADDED alongside normaliseEan() — that method's behaviour is unchanged.
     */
    public function isValidGtinChecksum(string $digits): bool
    {
        // All-digit only — never strip; a non-digit input is not a GTIN.
        if ($digits === '' || preg_match('/\D/', $digits) === 1) {
            return false;
        }

        $len = strlen($digits);
        if (! in_array($len, [8, 12, 13, 14], true)) {
            return false;
        }

        $checkDigit = (int) $digits[$len - 1];

        $sum = 0;
        // pos 0 = the digit immediately left of the check digit → weight 3.
        for ($i = $len - 2, $pos = 0; $i >= 0; $i--, $pos++) {
            $weight = ($pos % 2 === 0) ? 3 : 1;
            $sum += ((int) $digits[$i]) * $weight;
        }

        $expected = (10 - ($sum % 10)) % 10;

        return $expected === $checkDigit;
    }

    /**
     * 260905-ae5 — a GS1 company prefix with the product code left as zeros.
     *
     * `6936420000000` (Hikvision), `4740100000000` (Vision) and friends pass the
     * checksum perfectly well; they are simply not barcodes. Vision's feed emits
     * these wholesale — eleven products, two of them the SKU itself
     * (`4820817` → `4820817000002`).
     *
     * FIVE-or-more zeros then any final digit, NOT six-or-more. The check digit
     * is only 0 by coincidence: requiring `0{6,}$` caught `4740100000000` and
     * missed `4934292000003` — same shape, check digit 3 — which reached
     * products.ean the day the identity health check shipped (260828-plk).
     */
    public function isPlaceholderGtin(string $digits): bool
    {
        return preg_match('/^\d{4,8}0{5,}\d$/', $digits) === 1;
    }

    /**
     * First candidate that is a real GTIN, else null.
     *
     * Null is the correct answer when nothing qualifies: an empty GTIN is a
     * supported state for Google Shopping, a fabricated one is a disapproval —
     * and a duplicate fabricated one across several products invites scrutiny of
     * the whole feed rather than of the single item.
     */
    public function firstValidGtin(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $digits = trim((string) $candidate);
            if ($digits === '') {
                continue;
            }
            if ($this->isPlaceholderGtin($digits)) {
                continue;
            }
            if ($this->isValidGtinChecksum($digits)) {
                return $digits;
            }
        }

        return null;
    }
}
