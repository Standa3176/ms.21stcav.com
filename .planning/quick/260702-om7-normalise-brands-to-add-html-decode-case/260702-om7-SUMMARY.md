---
phase: 260702-om7-normalise-brands-to-add-html-decode-case
plan: 01
subsystem: product-auto-create
tags: [brands-to-add, taxonomy-hygiene, normalisation, tdd, quick-task]
requires:
  - "RefreshBrandsToAddCommand::buildBrandsToAddIndex (260702-h50)"
  - "ResolvesWooBrandKey trait (260702-h50)"
provides:
  - "HTML-decoded + case-collapsed + junk-excluded brands-to-add summary"
  - "config product_auto_create.brands_to_add_exclude"
  - "normaliseBrandName / isJunkBrand / pickCanonicalBrand helpers"
affects:
  - "products:refresh-brands-to-add to_add summary + cache + Suggestions Brand filter values"
tech-stack:
  added: []
  patterns:
    - "html_entity_decode(ENT_QUOTES|ENT_HTML5) + whitespace collapse for feed manufacturer names"
    - "case-insensitive grouping with mixed-case-preferred canonical (never title-case, preserve acronyms)"
key-files:
  created: []
  modified:
    - "app/Console/Commands/RefreshBrandsToAddCommand.php"
    - "config/product_auto_create.php"
    - "tests/Unit/Console/BrandsToAddIndexTest.php"
decisions:
  - "Never title-case a canonical brand — acronyms (APC/HP/2N) must survive; prefer an existing mixed-case variant instead."
  - "Junk brands are sourceable but never offered as creatable (per_sku brand=null), so the not-sourceable bucket stays distinct."
  - "Feed-only-all-caps brands (EATON with no Eaton variant) stay all-caps by design; rename in Woo on create if desired."
metrics:
  duration: "~15m"
  completed: "2026-07-02"
  tasks: 1
  files: 3
  commits: 2
---

# Phase 260702-om7 Plan 01: Normalise Brands-to-Add (HTML-decode + case-collapse + junk-exclude) Summary

Added name normalisation (HTML-entity-decode + trim + whitespace-collapse), case-insensitive dedupe with a mixed-case-preferred canonical (acronyms preserved), and config-driven junk-exclusion to `RefreshBrandsToAddCommand::buildBrandsToAddIndex`, so the brands-to-add list + the Suggestions Brand filter show one clean, safe-to-create brand per family instead of re-polluting the Woo taxonomy on one-click-create.

## What Was Built

### Config (`config/product_auto_create.php`)
- New key `brands_to_add_exclude` (default `['specials', 'un-branded', 'unbranded']`) — manufacturer names never offered as creatable Woo brands (case-insensitive). Consumables / non-brand buckets. Ops can extend the list without a code change.

### Command helpers (`RefreshBrandsToAddCommand`)
- `normaliseBrandName(string): string` — `html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')` + `trim` + `preg_replace('/\s+/u', ' ', …)`. `'VOGEL&#039;S' => "VOGEL'S"`.
- `isJunkBrand(string): bool` — case-insensitive membership test against `config('product_auto_create.brands_to_add_exclude')`.
- `pickCanonicalBrand(array<string,int>): string` — prefer a variant containing a lowercase letter (`\p{Ll}`) over an all-caps one (`'Brother'` over `'BROTHER'`); within the chosen pool pick highest total count, tie-break alphabetical. **Never title-cases** — acronyms `'APC'/'HP'/'2N'` stay intact.

### `buildBrandsToAddIndex` rework
- Normalises the manufacturer list at the **top** of the per-sku loop, so both `firstResolvableBrandKey` and the to-add name see decoded/clean names — `'VOGEL&#039;S'` now matches an existing Woo `"Vogel's"`.
- On-Woo / not-sourceable / multi-manufacturer branches **unchanged**.
- To-add path: junk → `per_sku {brand:null, on_woo:false, sourceable:true}` (sourceable but not counted); else accumulate into a case-insensitive group (`$groups[lower(brand)]['counts'][$variant]++` + sample SKUs).
- After the loop: per group pick the canonical (`count` = summed variant counts, `skus` = merged-unique capped at `SAMPLE_SKU_CAP=25`), then remap each `per_sku` placeholder brand to the canonical — so `per_sku` brand === the summary's canonical (one clean entry per family in the Suggestions Brand filter).

**Untouched (scope-tight):** `perform()`, `fetchManufacturers`, `indexSuggestionSkus`, evidence tagging, cache write, `--dry-run`, and the Mon-Fri 07:50 schedule + the ULID walk.

## Unit Cases (TDD)
Extended `tests/Unit/Console/BrandsToAddIndexTest.php` (RED-confirmed 4 failures, then GREEN):
- `BROTHER`+`Brother`+`Brother` → one `to_add` key `'Brother'`, count 3; `per_sku` a/b/c brand === `'Brother'`.
- `APC`+`APC` → `to_add` key `'APC'` count 2 (all-caps preserved, no title-casing).
- `VOGEL&#039;S` with Woo `"vogel's"` → `per_sku` on_woo=true, brand=`"Vogel's"`; NOT in to_add.
- `VOGEL&#039;S` without Woo → `to_add` key `"VOGEL'S"` (decoded), count 1.
- `SPECIALS` → `per_sku {brand:null, sourceable:true}`; NOT in to_add (junk).
- on-woo (`Yealink`) / not-sourceable (`[]`) / multi-mfr (`['Protect Plus','Yealink']` → Yealink) unchanged.

Original 5-SKU case unchanged (Trantec canonical is already mixed-case → still `'Trantec'` count 2).

## Verification
- `~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Console/BrandsToAddIndexTest.php tests/Unit/Console/RefreshBrandsToAddWalkTest.php` → **12 passed (43 assertions)** (9 index + 3 walk; `RefreshBrandsToAddWalkTest` GREEN, walk unchanged).
- `~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/RefreshBrandsToAddCommand.php config/product_auto_create.php` → `{"result":"pass"}`.

## Deviations from Plan
None — plan executed exactly as written (RED → GREEN, no refactor needed).

## Operator Notes (NOT executed by Claude)
- **Deploy:** push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Re-run `php artisan products:refresh-brands-to-add` — the to-add table now shows fewer, cleaner brands: `'BROTHER'`/`'Brother'` merged into `'Brother'`, `'VOGEL&#039;S'` shown as `"Vogel's"` (or matched to Woo), `'SPECIALS'`/`'Un-Branded'` gone. The Suggestions Brand filter shows one clean entry per brand family.
- Add more junk names to `config('product_auto_create.brands_to_add_exclude')` as you spot them.
- Feed-only-all-caps brands (e.g. `'EATON'` with no `'Eaton'` variant) stay all-caps — we don't title-case, to avoid breaking real acronyms — rename in Woo on create if desired. Still curate which brands to actually create (many are commodity IT brands you may not stock).

## Commits
- `31ed022` — test(260702-om7): add failing cases for brand normalise/dedupe/junk-exclude (RED)
- `6d775bb` — feat(260702-om7): normalise + case-collapse + junk-exclude brands-to-add (GREEN)

## Self-Check: PASSED
- `app/Console/Commands/RefreshBrandsToAddCommand.php` — FOUND (normaliseBrandName/isJunkBrand/pickCanonicalBrand present)
- `config/product_auto_create.php` — FOUND (brands_to_add_exclude present)
- `tests/Unit/Console/BrandsToAddIndexTest.php` — FOUND (6 new cases)
- Commit `31ed022` — FOUND
- Commit `6d775bb` — FOUND
