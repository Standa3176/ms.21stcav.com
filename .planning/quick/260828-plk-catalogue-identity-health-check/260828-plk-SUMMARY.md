---
id: 260828-plk
slug: catalogue-identity-health-check
date: 2026-08-28
status: complete
commit: 2524067
---

# Catalogue identity health check — summary

## Shipped

`products:identity-health-check` — read-only, no writes, no events, no Woo calls.

- `app/Console/Commands/CatalogueIdentityHealthCheckCommand.php`
- `tests/Feature/Products/CatalogueIdentityHealthCheckTest.php` (16 tests, 21 assertions)
- `routes/console.php` — daily 09:40 Europe/London, between the two pricing checks
- `tests/Architecture/StockUpdaterParityScheduleTest.php` — schedule pinned

## Verification

| Gate | Result |
|---|---|
| `pest tests/Feature/Products/CatalogueIdentityHealthCheckTest.php` | 16 passed |
| `pest tests/Architecture` | 126 passed, 557 assertions |
| `pint --test` (4 touched files) | pass |
| Run against production | **not yet done** |

## Decisions

**Only barcode faults fail the run.** A wrong or duplicated GTIN is a Google Shopping
disapproval — money out of the door. Names, images and suspect costs report and do not
alarm: they are a quality backlog, and an alarm that fires every morning for a known
backlog is one people mute, after which the real one is missed too. Same split and same
reasoning as 260825-n5v.

**Suspect cost is delegated, not reimplemented** — it calls the exact
`CeilingBlockClassifier` + `RuleResolver` path `pricing:health-check` uses, so the two
commands cannot drift into disagreeing about the same product.

**The SKU-derived check requires the value to ALSO fail its check digit.** A real GTIN
whose digits coincide with the SKU must never be reported as fabricated.

**Placeholder-name detection is deliberately conservative** — a name flags only when
removing the brand, the SKU and every generic noun leaves fewer than two words. "Vision
VFM-DSXP Desktop LCD Display Stand" survives; "AVer 60V2B10000AL Accessory" does not.

## Not done

- **Never run against production.** The verification that matters — that it independently
  rediscovers the known AVer/Philips SKU-derived barcodes, the Hikvision placeholders and
  the three placeholder names — has not happened. Until it does, the detectors are only
  proven against synthetic fixtures.
- No `--notify`. `pricing:health-check` earned notification after its findings proved out;
  this should prove out first.
- Reads `products.ean` only. The WordPress `_global_unique_id` population (~2,242 rows,
  the ones in the EAN issues spreadsheet) is a **separate and larger** set — see
  [[meetingstore-fabricated-eans]]. This command will not see them.

## Follow-ups

- Locate the code path that derives a GTIN from the SKU. This command detects the symptom
  daily; the cause is still unfound and still creating new bad rows.
- Ingest-time check-digit gate so supplier placeholders like `6931850000000` never land.
- `PublishSourcedEansCommand` and `WooGtinPublisher` already exist — worth checking
  whether the Woo-side GTIN field can be cleared and filled through them rather than the
  `clear-bad-gtins.sh` WordPress script.
