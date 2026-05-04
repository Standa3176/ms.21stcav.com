<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Competitor\Models\CompetitorCsvMapping;

/**
 * Phase 5 Plan 02 Task 1 — raw CSV price string → integer gross pennies.
 *
 * Strips currency symbols + whitespace, parses per the persisted decimal
 * mode (dot or comma), returns integer pennies gross OR null on
 * unparseable input.
 *
 * COMP-06 seam: this service OUTPUTS gross pennies only. VAT stripping is
 * Phase 3's `PriceCalculator::stripVat()` — NEVER duplicated here (P5-E
 * guard + StripVatReuseTest content-level grep catches drift).
 *
 * The single round() at the return boundary is the Phase 3 Pitfall 5
 * pattern: NO float intermediates leak out, NO compound rounding. A
 * comma-mode value of `1.234,56` becomes `"1234.56"` (thousands-dot
 * stripped, decimal-comma swapped to dot), then `(int) round(1234.56 *
 * 100)` → 123456 pennies.
 */
final class PriceParser
{
    /**
     * @param  string  $raw  raw CSV cell value
     * @param  string  $decimalMode  CompetitorCsvMapping::FORMAT_DOT|FORMAT_COMMA
     * @return int|null  integer gross pennies, or null if unparseable
     */
    public function toGrossPennies(string $raw, string $decimalMode): ?int
    {
        // Quick task 260504-edk — many supplier CSVs ship marketing-style sale
        // prices, e.g. "Was£5,525.57Save 19%£4,499.00" (avparts pattern). Extract
        // the post-"Save" amount as the actual selling price — the prior figure
        // is the recommended/list price, not what the supplier is charging.
        // Plain numeric prices fall through to the existing currency-strip logic.
        if (preg_match('/Save\s*\d+\s*%\s*[£$€]?\s*([\d,]+(?:\.\d{1,2})?)/iu', $raw, $m) === 1) {
            return $this->toGrossPennies($m[1], $decimalMode);
        }

        // Strip £, $, €, "GBP" literal, and any whitespace (incl. tabs & newlines).
        $clean = (string) preg_replace('/[£$€]|GBP|\s/iu', '', trim($raw));

        if ($clean === '') {
            return null;
        }

        if ($decimalMode === CompetitorCsvMapping::FORMAT_COMMA) {
            // European: "1.234,56" → "1234.56"
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            // UK/US: "1,234.56" → "1234.56"
            $clean = str_replace(',', '', $clean);
        }

        if (! is_numeric($clean)) {
            return null;
        }

        // Single round at the boundary (Phase 3 Pitfall 5 discipline).
        return (int) round(((float) $clean) * 100);
    }
}
