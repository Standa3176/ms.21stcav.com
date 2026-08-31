---
id: 260828-plk
slug: catalogue-identity-health-check
date: 2026-08-28
updated: 2026-08-31
status: complete
commit: 2524067, 3ea7cab, a133faf
---

# Catalogue identity health check — summary

## Shipped

`products:identity-health-check` — read-only, no writes, no events, no Woo calls.
Scheduled daily 09:40 Europe/London, between the two pricing checks.

- `app/Console/Commands/CatalogueIdentityHealthCheckCommand.php`
- `tests/Feature/Products/CatalogueIdentityHealthCheckTest.php` — 18 tests, 24 assertions
- `routes/console.php` — schedule entry
- `tests/Architecture/StockUpdaterParityScheduleTest.php` — schedule pinned

## Verification

| Gate | Result |
|---|---|
| `pest tests/Feature/Products/CatalogueIdentityHealthCheckTest.php` | 18 passed |
| `pest tests/Architecture` | 126 passed, 557 assertions |
| `pint --test` | pass |
| **Run against production 2026-08-28 → 08-31** | **found 76 barcode faults; catalogue now PASS at 4,267 products** |

## What it found on live data

**76 barcode faults on day one**, which drove a three-day clean-up: 97 barcodes sourced and
corrected, 1,050 recovered from Woo via `reconcile-ean-from-woo`, 24 cleared where no real
GTIN exists. Published catalogue went from 76 faults to **0**.

**A regression nothing else could have caught (08-31).** `43BDL5050D/00` was corrected on
08-28 to `8721038102253`, published to Woo, verified PASS — and by 08-31 `products.ean` held
the fabricated `43505000` again. The auto-create path had run over it. Woo was the only place
the good value survived. This proved the pipeline does not merely *create* bad barcodes, it
**destroys corrections**, which reframes the ingest gate from "should fix" to "must fix".

**A bug in this command, found by live data on day one** — commit `a133faf`. The placeholder
rule was `^\d{4,8}0{6,}$`, which only matches a padded prefix when its check digit happens to
be 0. It missed Vision's `4934292000003` (prefix + five zeros + check digit 3), which reached
`products.ean` the same evening the command shipped. Widened to `^\d{4,8}0{5,}\d$`; the fix
immediately surfaced **six more** placeholders the original missed, eight of them live on the
storefront.

## Section calibration — proven vs not

**Barcode sections are proven.** SKU-derived, placeholder, duplicate and invalid all fired on
real faults and cleared to zero. These are the sections that fail the run, and they earned it.

**Four sections are miscalibrated and must not be trusted yet:**

1. **`NO IMAGE` reads the wrong column.** It counts `gallery_image_urls`, which is only
   populated for auto-created products; legacy WC-migration products carry `woo_image_count`
   instead. Reported 5,365 — most are false positives.
2. **`PLACEHOLDER NAME` flags real model names.** `SmartVision 80`, `MTouch Plus`,
   `UC-MX70-T`, `Poly C60` are legitimate; the rule fires because stripping the SKU leaves
   only the brand. Genuine hits (`Credit`, `Offer`, `Lindy 36675`) are mixed in with them.
3. **`is_internal_only` products are not excluded** — `Credit`, `Offer`, `Quote Payment`.
4. **The `null` token is wrong.** LINDY's "Null Modem" cable is a real product type.

**Correction to the original assessment:** `UNRESOLVED TOKEN` is NOT noise. Of 29 hits, all
but the `null` case are genuine — supplier titles carrying `N/A` as an unfilled region field
("LOGITECH TAP SCHEDULER USB N/A", "TIDY MAGNET - N/A - WW"), live on the storefront.

## Not done

- The four calibration fixes above.
- No `--notify`. The barcode sections have now proven themselves and would justify it; the
  others should be fixed first so an alarm isn't attached to a section that cries wolf.
- Reads `products.ean` only. Local and Woo drift **independently** — clearing a local value
  does not clear Woo's `global_unique_id`, and Woo held 1,050 valid GTINs the app lacked.
  A local PASS is not a storefront PASS.

## Follow-ups, in priority order

1. **Guards where the draft generator writes `ean`:** reject a supplier EAN failing its check
   digit or matching `^\d{4,8}0{5,}\d$`; and **never overwrite a non-empty valid `ean` with an
   invalid one**. The second alone would have saved `43BDL5050D/00`.
2. **Rank supplier rows instead of picking arbitrarily.** One MPN has several `feeds_products`
   rows; the pipeline takes one without ranking. That single defect causes three symptoms —
   wrong RRP (`4807101`: £36 / £233 / £126.54 for one product), fabricated barcodes, and
   placeholder product names (`12XH000BUK` has a real title on one row and just the part
   number on another, after which image sourcing had nothing to validate against and matched
   ThinkCentre mini-PCs to a £3,242 room kit).
3. The four section calibrations.
