---
quick_id: 260504-edk
description: Sale-price + fallback-SKU parsing fixes for competitor CSV ingest
date: 2026-05-04
must_haves:
  truths:
    - PriceParser::toGrossPennies trims, strips currency, then is_numeric-checks. Pre-process to extract post-"Save" price before existing logic.
    - CompetitorCsvRowWriter line ~58 derives $sku via array_values($row)[$skuIdx]. Empty → return early as TYPE_INVALID_SKU_FORMAT. Insert fallback scan before that early-return.
    - Existing PriceParser tests live at tests/Feature/Competitor/PriceParserTest.php (not Unit).
    - No schema changes.
  artifacts:
    - app/Domain/Competitor/Services/PriceParser.php (modify toGrossPennies)
    - app/Domain/Competitor/Services/CompetitorCsvRowWriter.php (insert fallback SKU)
    - tests/Feature/Competitor/PriceParserTest.php (add 3 sale-price cases)
    - tests/Feature/Competitor/CompetitorCsvChunkJobTest.php (add 1 fallback-SKU case)
---

# Quick Task 260504-edk

## Goal

Convert ~790 errored rows / ~4% loss observed in live competitor CSV ingest into successfully-persisted rows.

## Tasks

### Task 1 — PriceParser sale-price extraction

In `toGrossPennies($raw, $decimalMode)`, after `trim($raw)` but before currency-strip:

```php
// Quick task 260504-edk — many supplier CSVs ship a marketing-style sale price
// e.g. "Was£5,525.57Save 19%£4,499.00". Extract the post-"Save" amount as the
// actual selling price; the prior £X.XX is the recommended/list price, not what
// the supplier is charging. Fall through to existing logic for plain numeric prices.
if (preg_match('/Save\s*\d+\s*%\s*[£$€]?\s*([\d,]+(?:\.\d{1,2})?)/iu', $raw, $m)) {
    return $this->toGrossPennies($m[1], $decimalMode);
}
```

### Task 2 — CompetitorCsvRowWriter fallback SKU

Where `$sku === ''` triggers TYPE_INVALID_SKU_FORMAT, INSERT a fallback scan first:

```php
$sku = trim((string) ($values[$skuIdx] ?? ''));

if ($sku === '') {
    // Quick task 260504-edk — onedirect (and similar) ship rows with empty col 0
    // but a real internal id elsewhere (col 3 in their case). Scan the row for the
    // first non-empty short alphanumeric token (length 1-64, no spaces) skipping
    // the SKU + price columns. Records as competitor_prices with that value as sku
    // — operationally Orphan unless the value happens to match a Product.
    foreach ($values as $i => $cell) {
        if ($i === $skuIdx || $i === $priceIdx) {
            continue;
        }
        $candidate = trim((string) $cell);
        if ($candidate === '' || strlen($candidate) > 64) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9._\-\/]+$/', $candidate) === 1) {
            $sku = $candidate;
            break;
        }
    }
}

if ($sku === '') {
    $this->writeParseError($run, CsvParseError::TYPE_INVALID_SKU_FORMAT, $row, 'empty SKU');
    $run->increment('rows_errored');
    return;
}
```

### Task 3 — Tests

PriceParser cases:
- `Was£5,525.57Save 19%£4,499.00` (FORMAT_DOT) → 449900
- `Was £2,659.00 Save 25% £1,999.00` (FORMAT_DOT, with spaces) → 199900
- `WAS £100 SAVE 10% £90.00` (FORMAT_DOT, uppercase) → 9000

CompetitorCsvChunkJob case:
- Row `['', '£99.99', 'Product Name with spaces', 'INTID-52501']`, mapping (sku=0, price=1) → 1 CompetitorPrice row with sku='INTID-52501' (col 2 has spaces, fails regex; col 3 matches → wins)

### Task 4 — Verify

- `vendor/bin/pest tests/Feature/Competitor/PriceParserTest.php tests/Feature/Competitor/CompetitorCsvChunkJobTest.php`
- `vendor/bin/deptrac analyse` → 0
- Manual: copy onedirect + avparts archive → incoming, run watch + queue:work, verify rows_errored drops sharply

### Task 5 — Commit

`feat(competitor-csv): parse Was£X Save Y% £Z sale-price strings + fall back to alt SKU column`
