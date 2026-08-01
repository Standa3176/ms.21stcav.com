---
quick_id: 260726-egr
description: products:reconcile-ean-from-woo — pull Woo GTIN back into local ean, checksum-gated (READ Woo, WRITE local only)
date: 2026-08-01
commits:
  - 154df9d  # test(RED): failing isValidGtinChecksum() unit tests
  - 8510d92  # feat(GREEN): isValidGtinChecksum() GTIN mod-10 gate
  - 8fb73a4  # test(RED): failing reconcile-ean-from-woo feature tests
  - 7d7e4b4  # feat(GREEN): products:reconcile-ean-from-woo command
status: completed
---

# Quick Task 260726-egr — Summary

Local `products.ean` drifted from Woo's real GTIN (`global_unique_id`). Proven on
prod 2026-07-26: **A30-020** local ean `6938820000000` (13 digits but FAILS the
EAN-13 check digit) vs Woo `0841885115294` (valid). Root cause: the shared
`NormalisesEan` trait length-checked and rejected all-zero/all-nine sentinels but
did **no check-digit validation**, so precision-mangled values passed as "valid".
The Merchant feed reads local `products.ean`, so bad local values would get
products disapproved by Google even though Woo held the correct GTIN.

This task (a) adds the missing checksum gate and (b) adds a command that pulls
Woo's GTIN back into the local columns — the REVERSE of `WooGtinPublisher` —
**checksum-gated, READ-only against Woo, LOCAL writes only.**

## What changed

### Task 1 — `NormalisesEan::isValidGtinChecksum()` (the missing gate)

- New `public function isValidGtinChecksum(string $digits): bool` on the shared
  `App\Console\Concerns\NormalisesEan` trait. Standard GTIN mod-10: rightmost
  digit is the check digit; working right-to-left from the digit left of it,
  weights alternate 3,1,3,1…; check = `(10 − sum % 10) % 10`. Valid for the real
  GTIN lengths only — **8 / 12 / 13 / 14**. Non-standard length or any non-digit
  character → `false` regardless of whether mod-10 would coincidentally pass.
- **`normaliseEan()` is unchanged** — the method was ADDED alongside it. The 15
  pre-existing byte-identical `normaliseEan()` cases stay green.

### Task 2 — `products:reconcile-ean-from-woo`

Signature: `{--skus=} {--all} {--apply} {--csv=} {--read-retries=4} {--read-backoff-ms=3000}`.

- **Scope.** Default = simple+publish products with a `woo_product_id` whose local
  `ean` is EMPTY or FAILS the checksum (the clearly-broken set — PHP-filtered
  since the checksum can't run in SQL). `--skus=A,B,C` targets specific SKUs (any
  validity). `--all` scans every simple+publish product with a `woo_product_id`
  (warns it is many reads on a flaky endpoint).
- **Per product:** READ Woo `global_unique_id` via `WooClient::get("products/{id}")`.
  Verdicts:
  | Local ean | Woo GTIN | Verdict |
  |---|---|---|
  | empty/invalid | valid | **FIX** — set local `ean` + `woo_gtin` = Woo GTIN |
  | valid | valid, differs | **CONFLICT** — report only, NEVER auto-change |
  | valid | matches | `in_sync` (skip) |
  | any | empty/invalid | `no_valid_woo_gtin` (can't fix from Woo) |
  | any | read failed all retries | `read_failed` (leave local untouched) |
- **Dry-run by DEFAULT.** Only `--apply` writes, and it writes **LOCAL columns
  only** (`products.ean`, `products.woo_gtin`) via `forceFill()->saveQuietly()`.
  The command **NEVER** calls `WooClient::put/post/patch/delete`, never touches
  the storefront, and does **NOT** read `WOO_WRITE_ENABLED`.
- **Read resilience (flaky endpoint, 260726-slw lesson).** Each Woo GET is wrapped
  in retry-with-backoff: `--read-retries` (default 4) additional attempts,
  exponential `--read-backoff-ms` (default 3000 → 3s, 6s, 12s, 24s). Gentle 200ms
  pacing between reads. All routed through the injectable `App\Console\Support\Sleeper`
  seam (tests bind a recording no-op — never waits). A read that fails every
  attempt is counted `read_failed` and the local row is left untouched — never
  "fixed" from a failed read.
- **Output:** summary table (scanned / fixed / conflicts / no_valid_woo_gtin /
  in_sync / read_failed) + a `verdicts:` grep-line + a Fixes table + a Conflicts
  table + optional `--csv` with per-product rows
  (`sku,woo_id,local_ean,woo_gtin,local_valid,woo_valid,verdict`).

## Files

- `app/Console/Concerns/NormalisesEan.php` — **+ `isValidGtinChecksum()`** (normaliseEan untouched).
- `app/Console/Commands/ReconcileEanFromWooCommand.php` — **new** command (uses the trait, injects `WooClient` + `Sleeper`).
- `tests/Unit/Console/Concerns/NormalisesEanTest.php` — **+12 cases** for the validator (15 existing kept green).
- `tests/Feature/Console/ReconcileEanFromWooCommandTest.php` — **new** 10-case suite (stubbed `WooClient`, in-memory products, recording `Sleeper`, no real network / no real sleeping).

## Tests / gates

- **pest**
  - `NormalisesEanTest`: **27 passed** (15 existing + 12 new validator cases).
  - `ReconcileEanFromWooCommandTest`: **10 passed, 35 assertions** — FIX (dry-run
    + `--apply`), CONFLICT (`--apply`, unchanged), in_sync, no_valid_woo_gtin,
    read_failed (retries=2 → 3 attempts, untouched), `--skus` scoping, default
    broken-set scope (valid-local excluded), `--csv` rows.
  - **No-WooClient-write assertion IS present and passing:** the stub records
    every `put/post/patch/delete` into `$stub->writes`; the FIX (dry-run), FIX
    (`--apply`), CONFLICT and read_failed cases all assert `$stub->writes === []`.
- **pint** — `{"result":"pass"}` on all 4 changed files (the feature test was
  auto-reformatted by pint during GREEN and committed formatted).
- **vendor/bin/deptrac analyse** — **0 violations** (command + trait live in
  `app/Console/*`, outside every domain layer, same as the sibling
  `BackfillCategoryFromWooCommand`).
- **php artisan route:list --path=admin** — exit **0**.

PHP via Herd (`~/.config/herd/bin/php84/php.exe`, PHP 8.4.22).

## Operator run instructions (prod)

Safe to run anytime — LOCAL-only writes, no storefront impact, no Woo writes.

```bash
# 1) Dry-run first — review conflicts before writing anything.
php artisan products:reconcile-ean-from-woo --csv=storage/app/ean-reconcile.csv

# 2) Inspect the CSV: FIX rows are safe; CONFLICT rows need human judgement
#    (both local + Woo are valid GTINs but differ — the command NEVER touches these).

# 3) Apply the fixes (writes local products.ean + woo_gtin only).
php artisan products:reconcile-ean-from-woo --apply
```

Known first fixes: **A30-020** (local `6938820000000` → Woo `0841885115294`) and
**DS-D6075UN**. `--skus=A30-020,DS-D6075UN` targets just those to verify the flow
before a full run.

## Guardrails honoured

- LOCAL writes only; READ-only against Woo; **no Woo writes**; no migration; no
  `WOO_WRITE_ENABLED` change; `normaliseEan()` behaviour, `WooGtinPublisher`, and
  `ShoppingCandidatesCommand`/`Scanner` all untouched. All Woo I/O via `WooClient`.
- Driver-portable (tests run on SQLite; verdict logic + `forceFill`/`saveQuietly`
  are Eloquent, no raw SQL; string SKUs bound as strings).
- No push, no deploy, no live prod command run.
- Atomic commits on `main` (RED → GREEN per task).
- Left pre-existing working-tree noise UNSTAGED: `storage/app/research/supplier-probe.json`
  (deleted), `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified),
  untracked `.claude/`.

## Deviations from plan

- **[Rule 2 — safety pacing]** The plan signature has no pacing flag, but "gentle
  pacing between reads" was required. Implemented as a fixed `READ_PACE_MS = 200`
  constant routed through the injectable `Sleeper` (byte-identical to a raw
  `usleep` in prod; no-op in tests). Keeps the signature exactly as specified
  while honouring the flaky-endpoint pacing requirement.
- **[Clarification]** `--read-retries` is interpreted as retries IN ADDITION to
  the first attempt (retries=4 → 5 total attempts; retries=2 → 3, asserted by the
  read_failed test). Matches the 260726-slw exponential-backoff sequence.
