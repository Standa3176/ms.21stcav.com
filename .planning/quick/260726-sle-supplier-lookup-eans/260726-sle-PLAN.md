# 260726-sle — `supplier:lookup-eans`: read-only supplier_db EAN lookup for a SKU list

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** operator needs the supplier_db EAN for a specific SKU list (the 83 Google Shopping candidates) to
correct/verify the Merchant feed. The existing `products:backfill-merchant-feed --dry-run` can't do it —
its candidate selection SKIPS SKUs that already have a (even corrupted) local `products.ean`, so A30-020 /
DS-D6075UN return "0 candidate products". This task exposes the supplier_db EAN for ANY given SKU,
read-only, with no dependence on local product state, and writes a CSV to merge into the review sheet.

## Reuse (do NOT duplicate credential/connection logic)
`BackfillMerchantFeedCommand::lookupSupplierEans()` already does exactly the needed remote read:
- Connects to supplier_db via `mysqli` using the SAME credential resolution that command uses (resolve it
  the identical way — read that method and reuse its connection setup verbatim; do NOT invent a new
  credential path).
- Two-pass match, `product_excluded = 0`:
  `SELECT LOWER(TRIM(suppliersku)) AS sku_key, ean FROM feeds_products WHERE product_excluded=0 AND LOWER(TRIM(suppliersku)) IN (…)`
  then the same on `LOWER(TRIM(mpn))` for SKUs unmatched by suppliersku.
Prefer extracting that lookup into a small shared read-only service (e.g. `SupplierEanLookup`) that BOTH
the backfill command and this new command call — but if extraction risks changing backfill behaviour,
instead replicate the query + connection read verbatim in the new command and note the duplication in the
SUMMARY. Either way: **byte-identical query semantics, READ-ONLY, no writes to supplier_db or products.**

## Task — `supplier:lookup-eans` (READ-ONLY, TDD)
Signature: `{--skus= : comma-separated SKUs} {--skus-file= : path, one SKU per line} {--csv= : output path}`.
- Resolve the SKU set from `--skus` and/or `--skus-file` (union, de-duped, trimmed). Error clearly if none.
- Look up each SKU's supplier EAN via the reused lookup (suppliersku match first, then mpn).
- For each SKU compute: `supplier_ean` (raw), `normalised` (via `NormalisesEan::normaliseEan`),
  `checksum_valid` (via `NormalisesEan::isValidGtinChecksum` on the normalised value), `matched_by`
  (`suppliersku` | `mpn` | `none`), `found` (yes/no).
- Print a summary (total / found / valid / not-found) + a table (cap console rows, note if capped), and
  when `--csv=` given, write ALL rows: `sku,supplier_ean,normalised,checksum_valid,matched_by,found`.
- **Bounded + safe:** chunk the IN() lists (e.g. 500 SKUs/query) so a large list can't build a monster
  query; single connection, closed in a finally; **no writes anywhere** (no products update, no supplier_db
  write). Read-only mysqli only.

## Verify (TDD, no real network)
- `pest`: inject a fake lookup (seam) returning a fixed sku→ean map so tests need no DB. Assert:
  suppliersku-match wins over mpn; unmatched SKU → found=no/matched_by=none; checksum_valid computed via
  the NormalisesEan trait (corrupt `6938820000000` → false, valid EAN → true); `--csv` writes the expected
  header + one row per input SKU; `--skus` + `--skus-file` union + de-dupe; empty input errors cleanly.
  Assert NO write call of any kind.
- `php artisan route:list --path=admin` exit 0; `pint`; `vendor/bin/deptrac analyse` → 0 violations
  (mirror where BackfillMerchantFeedCommand / MysqlSupplierFeedReader sit re: the WpDirectDb lane — the
  raw mysqli supplier_db read is already an accepted pattern there; keep deptrac green).

## Guardrails / SUMMARY
- READ-ONLY: only SELECTs against supplier_db `feeds_products`; no writes to supplier_db or local products;
  no migration; no Woo calls; no `WOO_WRITE_ENABLED` change. Driver-portable for the local test seam.
- Do NOT change `BackfillMerchantFeedCommand` behaviour (if extracting the lookup, keep its output/counts
  byte-identical). Do NOT stage pre-existing noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP via Herd (~/.config/herd/bin/php84/php.exe). Atomic commits on `main`. No push/deploy. Write
  `260726-sle-SUMMARY.md` with the exact prod command, e.g.
  `php artisan supplier:lookup-eans --skus-file=storage/app/shortlist-skus.txt --csv=storage/app/supplier-eans.csv`.
