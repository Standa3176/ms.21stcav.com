# 260726-egr — `products:reconcile-ean-from-woo`: pull Woo's GTIN back into local, checksum-gated

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** the local `products.ean` column has drifted from Woo's real GTIN. Proven on prod 2026-07-26:
A30-020 local ean = `6938820000000` (corrupted — 13 digits but fails the EAN-13 check digit), while Woo's
`global_unique_id` = `0841885115294` (valid). Root cause: the shared `NormalisesEan` trait length-checks
and rejects all-zero/all-nine but does **NO check-digit validation**, so precision-mangled values pass. The
`products:shopping-candidates` Merchant shortlist reads local `products.ean`, so bad local values would get
products **disapproved** by Google Merchant even though Woo holds the correct GTIN. Woo is the better source
of truth for GTINs; this task reconciles local → Woo, safely.

## Data model (confirmed)
- `products.ean` — local EAN (what shopping-candidates + Merchant feed read).
- `products.woo_gtin` — local mirror of what was last pushed to Woo.
- Woo stores GTIN in `global_unique_id` (WC 9.x). `WooGtinPublisher` PUTs local ean → Woo
  `global_unique_id` and sets local `woo_gtin`. This task is the REVERSE (Woo → local), read-from-Woo only.

## Task 1 — Add a GTIN check-digit validator (the missing gate)
- Extend the `App\Console\Concerns\NormalisesEan` trait (single source of truth) with a new method
  `isValidGtinChecksum(string $digits): bool` implementing the standard GTIN mod-10 check digit (works for
  GTIN-8/12/13/14: right-to-left weights 3,1,3,1…, check = (10 − sum%10)%10). Do NOT change the existing
  `normaliseEan()` behaviour (other callers depend on it byte-identically) — ADD alongside it.
- Unit-test the validator: known-good EAN-13 (`0841885115294` true, `4006381333931` true), known-bad
  (`6938820000000` false, `6936420000000` false), GTIN-8/12/14 goods, non-digit/short → false.

## Task 2 — `products:reconcile-ean-from-woo` (READ Woo, WRITE local only; TDD)
Signature: `{--skus=} {--all} {--apply} {--csv=} {--read-retries=4} {--read-backoff-ms=3000}`.
- **Scope (default):** products with a `woo_product_id` whose local `ean` is EMPTY **or** fails
  `isValidGtinChecksum()` — i.e. the clearly-broken set. `--skus=A,B,C` targets specific SKUs. `--all` scans
  every publish/simple product with a `woo_product_id` (log a warning that this is many Woo reads on a
  flaky endpoint; use the retry+pacing below).
- **Per product:** READ Woo `global_unique_id` via `WooClient::get("products/{wooId}")` (GET only). Compare:
  | Local ean | Woo GTIN | Action |
  |---|---|---|
  | empty/invalid | valid | **FIX** — set local `ean` = Woo GTIN and `woo_gtin` = Woo GTIN |
  | valid | valid, differs | **CONFLICT** — report only, DO NOT change (needs human judgement) |
  | valid | matches | skip (in sync) |
  | any | empty/invalid | report `no_valid_woo_gtin` (can't fix from Woo) |
- **Dry-run by default.** Only `--apply` writes, and it writes **LOCAL columns only** (`products.ean`,
  `products.woo_gtin`) via `saveQuietly()` / `forceFill` — **NEVER** calls `WooClient::put/post/delete`,
  never touches the storefront, and does NOT depend on `WOO_WRITE_ENABLED` (that flag gates Woo writes, not
  local ones). Assert this in tests.
- **Read resilience (flaky endpoint):** wrap each Woo GET in retry-with-backoff (`--read-retries`,
  `--read-backoff-ms`, exponential) — the same lesson as 260726-slw; a transient non-JSON/timeout read must
  retry, not silently skip. Gentle pacing between reads. If a product's read ultimately fails, count it as
  `read_failed` and leave local untouched (never "fix" from a failed read).
- **Output:** funnel/summary (scanned, fixed, conflicts, no_valid_woo_gtin, in_sync, read_failed) + a table
  of fixes and conflicts, plus `--csv=` with per-product rows (sku, woo_id, local_ean, woo_gtin,
  local_valid, woo_valid, verdict).

## Verify (TDD, no real network)
- `pest` (stubbed WooClient returning fake `global_unique_id`s, in-memory products):
  - fix path: local invalid + Woo valid ⇒ dry-run reports FIX but writes nothing; `--apply` sets local
    ean+woo_gtin; assert NO WooClient write call ever.
  - conflict path: both valid but differ ⇒ reported as conflict, local unchanged even with `--apply`.
  - in-sync + no_valid_woo_gtin + read_failed (Woo GET throws through all retries → left untouched, counted).
  - `--skus` scoping; default scope selects only empty/invalid-local rows; checksum validator wired in.
  - driver-portable (SQLite test / MariaDB prod).
- `php artisan route:list --path=admin` exit 0; `pint`; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / SUMMARY
- LOCAL writes only; READ-only against Woo; no Woo writes; no migration; no `WOO_WRITE_ENABLED` change; no
  change to `NormalisesEan::normaliseEan()` behaviour, `WooGtinPublisher`, or shopping-candidates. All Woo
  I/O via WooClient. Do NOT stage pre-existing noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP via Herd (~/.config/herd/bin/php84/php.exe). Atomic commits on `main`. No push/deploy. Write
  `260726-egr-SUMMARY.md` with the exact prod usage: dry-run first
  (`php artisan products:reconcile-ean-from-woo --csv=storage/app/ean-reconcile.csv`), review conflicts,
  then `--apply`; note it's safe anytime (local-only writes, no storefront impact), and that A30-020 /
  DS-D6075UN are the known first fixes.
