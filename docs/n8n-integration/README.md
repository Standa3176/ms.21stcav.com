# n8n Competitor CSV Integration

This document describes the file-drop contract between n8n (the scraping
orchestrator) and the Laravel RAMS platform (the consumer). It is the
authoritative source for the filename convention, atomic-write protocol,
column-naming heuristics, encoding expectations, and first-time competitor
UX.

Every detail below is enforced by code under `app/Domain/Competitor/` and
Plan 05-02 feature tests in `tests/Feature/Competitor/`.

## Directory Convention

n8n writes competitor CSV files to this path on the Laravel VPS:

```
storage/app/competitors/incoming/
```

The Laravel watcher (`competitor:watch`, scheduled every 5 minutes) moves
files through a 4-stage pipeline:

```
incoming/     # n8n drops here — mtime > 30s gate triggers pickup
processing/   # atomic rename target before IngestCompetitorCsvJob fires
archive/YYYY-MM-DD/   # success destination (pruned at 90 days by Plan 05-05)
quarantine/YYYY-MM-DD/   # ambiguous-mapping OR filename-failure destination
```

Only `incoming/` needs to be writable by the n8n process. All other paths
are Laravel-managed.

## Filename Convention

Files MUST match this exact pattern:

```
{competitor_slug}_{YYYY-MM-DD}.csv
```

Examples:

- `acme_2026-04-19.csv`
- `avshop_2026-04-19.csv`
- `logitech-distrib_2026-04-19.csv`

**Slug rules:**

- Lowercase `a-z`, digits `0-9`, hyphens `-` and underscores `_`
- Maximum 64 characters before the date
- The full regex is: `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`

Files that don't match are moved to `storage/app/competitors/quarantine/`
and surface in the Filament "CSV Ingest Issues" page with
`issue_type=invalid_filename`.

## Atomic Write Protocol

n8n MUST use the `.tmp → rename` pattern to avoid the watcher reading a
mid-write file:

1. Write the CSV first to:
   `storage/app/competitors/incoming/{slug}_{date}.csv.tmp`
2. When the write is complete and the buffer flushed, rename the file to
   strip the `.tmp` suffix.

The watcher only scans for `.csv` files — `.tmp` files are ignored. As a
secondary safety, the watcher enforces a `filemtime > 30s` gate so a slow
rename (e.g. across a network share) doesn't trigger premature pickup.

**On same-volume NTFS / ext4 / APFS, `rename()` is atomic.** Do NOT write
across volumes.

## CSV Format Expectations

### Encoding

- UTF-8 with BOM is safest.
- Windows-1252 and ISO-8859-1 are auto-detected via `mb_detect_encoding`.
- UTF-16 LE and BE are auto-detected from the BOM.
- Detection falls back to UTF-8 + a warning log entry when bytes are
  ambiguous — the ingest proceeds but you may see garbled multibyte
  characters downstream.

### Header Row

Required. First row is the column headers. Recognised columns:

- **SKU column** — one of: `sku`, `mpn`, `part_no`, `part number`,
  `part_number`, `product code`, `product_code` (case + whitespace
  insensitive; CONTAINS-matching — so `Product Code` matches `product
  code`).
- **Price column** — one of: `price`, `rrp`, `cost`, `£`, `gbp`,
  `price_gbp`, `price_ex_vat`, `price_inc_vat`.

The ingester picks the first matching column in each category. If either
category has zero candidates, the file is quarantined with
`issue_type=ambiguous_mapping` and an admin resolves via the Filament
"CSV Ingest Issues" page (Plan 05-04).

### Decimal Format

Both dot-as-decimal (`1,234.56`) and comma-as-decimal (`1.234,56`) are
supported. The format is auto-detected from the first 10 non-header
price-column rows — majority rule, defaults to dot on empty/tied.

The detected format is persisted per-competitor, so subsequent ingests
for the same competitor skip re-detection.

### Currency Symbols

`£`, `GBP`, `€` and any whitespace are stripped automatically. All
values are assumed **GBP inc-VAT** — the ingester uses Phase 3's
`PriceCalculator::stripVat()` helper to produce the ex-VAT integer
pennies column (`price_pennies_ex_vat`) for margin analysis.

## Example CSV

```csv
sku,price,brand
LOGI-C920,£89.99,Logitech
POLY-STUDIO-X30,£1299.00,Poly
JBL-310,£129.95,JBL
```

European variant (semicolon delimiter auto-detected by spatie/simple-excel;
comma-decimal auto-detected by DecimalFormatDetector):

```csv
sku;price
LOGI-C920;89,99
POLY-STUDIO-X30;1.299,00
JBL-310;129,95
```

## First-Time Competitor Ingest

When a new slug is seen for the first time, a `competitors` row is
auto-created with `status=pending` and `name = {slug}`. An admin must then
log into Filament (`/admin/competitors`) to:

1. Set the display name (free text)
2. Add the website URL (optional)
3. Optionally record MAP-policy notes
4. Flip `status` to `active`

Pending competitors DO get their CSVs ingested — but margin-change
suggestions (Plan 05-03) will suppress until `status=active`.

## Troubleshooting

| Symptom | Diagnosis |
|---------|-----------|
| File sat in `incoming/` for more than 10 minutes | Check `php artisan schedule:list` — `competitor:watch` should list every 5 minutes |
| File in `quarantine/` | Check the Filament "CSV Ingest Issues" page — likely an ambiguous-mapping or invalid-filename error |
| No suggestions fired for a known-drifted competitor | Check `products.last_sales_count_90d` (must be ≥ 10 — Plan 05-03) OR `competitor_prices` row count (must be ≥ 3 consecutive scrapes) |
| Feed marked "stale" in Filament | n8n hasn't dropped a file in more than 48h; check the n8n workflow on the n8n side |
| Encoding-detection warning in logs | The CSV's encoding couldn't be confidently detected; re-export from source with UTF-8 BOM |
| Parse errors (`unparseable_price`) | Check the raw CSV — the price column contains non-numeric values (HTML fragments, stock keywords, etc) |

## Related Documentation

- Phase 5 CONTEXT: `.planning/phases/05-competitor-analysis/05-CONTEXT.md`
- Phase 5 RESEARCH (§1 watcher, §2 encoding, §5 chunking, §6 quarantine):
  `.planning/phases/05-competitor-analysis/05-RESEARCH.md`
- COMP-01..COMP-12 requirements: `.planning/REQUIREMENTS.md`
- Phase 4 analogous README pattern:
  `docs/wordpress-snippets/README.md` (for Woo webhook setup)
