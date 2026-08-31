---
id: 260828-plk
slug: catalogue-identity-health-check
date: 2026-08-28
status: in-progress
---

# Catalogue identity health check

## Problem

Pricing has daily automated monitoring (`pricing:health-check`, `pricing:audit-movements`).
**Identity data has none.** No check ever asks "does this product's name, image and
barcode describe a real thing?" — which is why ~2,242 fabricated barcodes sat on the
storefront for months and were found only because a human went looking.

Concrete faults found by hand on 2026-08-27/28 that nothing would have caught:

- `AVer 60V2B10000AL **Accessory**`, `Lenovo 64ACGAT1EK **Professional AV Solution**`,
  `Barco R9861700T01 **Replacement Part / Accessory**` — placeholder names; nobody
  established what the product is.
- `MB65PRO-A02` — title contains the literal token `nan`.
- `61U3010000AC` holds `613010000` — the SKU with its letters stripped out. This is the
  single most diagnostic signature of the fabrication and appears across every Philips
  `xxBDLxxxx/00` and every AVer `61Uxxxxxxx`.
- `DS-D6055UN-D/S` holds `6931850000000` — a GS1 company prefix zero-padded to 13.
  Three Hikvision products shared one such value.
- Eight products published with a single image, several accepted on product *type*
  rather than identity.

## Scope

ONE read-only command, `products:identity-health-check`. Report only — no writes, no
events, no Woo calls. Mirrors `PricingHealthCheckCommand` (260825-n5v) in shape, flags
and output conventions so operators meet one idiom, not two.

### Checks

| Section | Rule | Severity |
|---|---|---|
| `SKU-DERIVED BARCODE` | GTIN digits === the SKU's digits | fault |
| `PLACEHOLDER BARCODE` | GS1 prefix followed by ≥6 trailing zeros | fault |
| `DUPLICATE BARCODE` | same zero-normalised GTIN on >1 product | fault |
| `INVALID BARCODE` | check digit fails, or length not in {8,12,13,14} | fault |
| `PLACEHOLDER NAME` | name is brand + SKU + a generic noun and nothing else | report |
| `UNRESOLVED TOKEN IN TITLE` | standalone `nan` / `N/A` / `null` / `undefined` / `TBC` | report |
| `NO IMAGE` | empty gallery | report |
| `SINGLE IMAGE` | exactly one image | report |
| `SUSPECT COST` | delegated to `CeilingBlockClassifier` + `RuleResolver` | report |

**Barcode faults alarm; the rest report.** A wrong or duplicated GTIN causes Google
Shopping disapproval — money out of the door. A thin gallery is a quality backlog, and
an alarm that fires daily for a known backlog is one people stop reading. Same reasoning
as 260825-n5v's below-cost/suspect-cost split.

**Suspect cost is delegated, not reimplemented.** It reuses the exact
`CeilingBlockClassifier` + `RuleResolver` path `pricing:health-check` uses, so the two
commands cannot drift into disagreeing about the same product.

## Tasks

1. `app/Console/Commands/CatalogueIdentityHealthCheckCommand.php` — the command.
2. `tests/Feature/Products/CatalogueIdentityHealthCheckTest.php` — one test per check,
   plus the load-bearing negatives: a legitimate short name must NOT flag, a genuine
   12-digit UPC-A must NOT flag as invalid, and a product with a real GTIN whose digits
   coincidentally appear in the SKU must NOT flag as SKU-derived.
3. Register in `routes/console.php` — daily 09:40, after `pricing:audit-movements`
   (09:35) so the two reports land together.
4. Pin the schedule in `tests/Architecture/StockUpdaterParityScheduleTest.php`,
   matching the existing pinning convention.

## Out of scope

- Fixing any of the data. Report only.
- The Woo-side GTIN field. This reads `products.ean`; the WordPress `_global_unique_id`
  population (~2,242 rows) is a separate, larger job.
- Notifications. `pricing:health-check` earned `--notify` after its findings proved out;
  this one should prove out first.

## Verification

- `vendor/bin/pest tests/Feature/Products/CatalogueIdentityHealthCheckTest.php`
- `vendor/bin/pest tests/Architecture`
- `vendor/bin/pint --test`
- Run against prod read-only and confirm it independently rediscovers the known faults:
  the AVer/Philips SKU-derived barcodes, the Hikvision placeholders, and the three
  placeholder names.
