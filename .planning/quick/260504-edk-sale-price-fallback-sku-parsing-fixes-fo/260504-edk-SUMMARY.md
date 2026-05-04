---
quick_id: 260504-edk
description: Sale-price + fallback-SKU parsing fixes for competitor CSV ingest
date: 2026-05-04
commit: 53fa2ac
status: completed
---

# Quick Task 260504-edk — Summary

## Real-world result (re-ingested archived files against the new parser)

| Competitor | Before | After | Recovered | Remaining |
|---|---|---|---|---|
| avparts (sale-price pattern) | 71 errors | **0** | **71 ✓ 100%** | 0 |
| onedirect (empty-SKU fallback) | 324 errors | 153 | **171 ✓ 53%** | 153 (truly missing prices) |
| **Net** | **395** | **153** | **242** | 153 (unfixable — empty source) |

The remaining 153 onedirect errors are products where the source CSV has literally no price (`,,Poly Blackwire BW3320 USB-C,52507,...` — col 0 + col 1 both empty). Supplier data-quality issue; can't be solved in code.

## Files changed (4, +74 / 0)

### 1. `app/Domain/Competitor/Services/PriceParser.php` (+9 / 0)

Pre-pass regex extracts the post-"Save" price BEFORE the existing currency-strip + numeric-check logic:

```php
if (preg_match('/Save\s*\d+\s*%\s*[£$€]?\s*([\d,]+(?:\.\d{1,2})?)/iu', $raw, $m) === 1) {
    return $this->toGrossPennies($m[1], $decimalMode);
}
```

Handles `Was£5,525.57Save 19%£4,499.00`, `Was £100 Save 10% £90.00` (spaces), `WAS £100 SAVE 10% £90.00` (uppercase). Non-marketing prices fall through unchanged.

### 2. `app/Domain/Competitor/Services/CompetitorCsvRowWriter.php` (+22 / 0)

When `$sku === ''`, scan the row for a fallback identifier:

```php
foreach ($values as $i => $cell) {
    if ($i === $skuIdx || $i === $priceIdx) continue;
    $candidate = trim((string) $cell);
    if ($candidate === '' || strlen($candidate) > 64) continue;
    if (preg_match('/^[A-Za-z0-9._\-\/]+$/', $candidate) === 1) {
        $sku = $candidate;
        break;
    }
}
```

Filters: empty cells, oversized cells (>64 chars), cells with spaces / special chars (e.g. product names). Picks the first plausible identifier-shaped token.

### 3. `tests/Feature/Competitor/PriceParserTest.php` (+19 / 0)

3 new cases covering the sale-price extraction (uppercase, with spaces, no spaces).

### 4. `tests/Feature/Competitor/CompetitorCsvChunkJobTest.php` (+24 / 0)

1 new case — verifies fallback picks col 3 (`INTID-52501`) when col 0 is empty AND col 2 has spaces (must skip the product name and find the actual SKU).

## Tests + verification

- 16 tests passing across both files (4 new + 12 existing)
- `vendor/bin/deptrac analyse` — 0 violations
- Live re-ingest proof: replayed avparts + onedirect from archive/ → competitor_prices count went from 20,103 → 22,363 (+2,260 rows), error rate dropped from 395 → 153

## What's NOT fixable in code

153 onedirect rows that have NO price in the source CSV. These could be:
- Products awaiting price entry on supplier's side
- Discontinued / call-for-price items
- Data export bugs

If ops wants to capture these as "price = unknown" rows for tracking, that's a different feature (would need a new column `price_status` enum). Out of scope here.

## Commit

`53fa2ac` — feat(competitor-csv): parse Was£X Save Y% £Z sale-price strings + fall back to alt SKU column
