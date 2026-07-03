---
phase: 260703-c8q-woobrandcreator-fuzzy-matches-existing-w
plan: 01
subsystem: product-auto-create
tags: [woo-brands, taxonomy, fuzzy-match, brand-dedupe]
requires:
  - "TaxonomyResolver::resolveBrand (bestMatchId, FUZZY_THRESHOLD 0.85)"
  - "WooBrandCreator (260702-qd8)"
provides:
  - "WooBrandCreator existence check reuses an existing brand via fuzzy match before POSTing"
affects:
  - "CreateWooProductJob (per-row create) — via WooBrandCreator"
  - "DraftFromSuggestionsCommand (bulk create) — via WooBrandCreator"
tech-stack:
  added: []
  patterns:
    - "Both create paths converge on the same fuzzy brand matcher (no duplicate brand terms)"
key-files:
  created: []
  modified:
    - app/Domain/ProductAutoCreate/Services/WooBrandCreator.php
    - tests/Unit/ProductAutoCreate/WooBrandCreatorTest.php
decisions:
  - "findExistingId delegates to TaxonomyResolver::resolveBrand instead of an exact-name loop"
  - "Threshold stays 0.85 (containment scores 0.9 → matches; genuinely-new names stay below → still create)"
metrics:
  duration: "~5 min"
  completed: 2026-07-03
---

# Quick Task 260703-c8q: WooBrandCreator Fuzzy-Matches an Existing Woo Brand Summary

WooBrandCreator's existence check now delegates to the same fuzzy matcher the per-row create path
trusts (`TaxonomyResolver::resolveBrand` → `bestMatchId`, `FUZZY_THRESHOLD 0.85`), so a more-specific
feed manufacturer like `'Barco Clickshare'` reuses the existing `'Barco'` term instead of POSTing a
near-duplicate brand.

## The Gap: Exact vs Fuzzy

The 260702-qd8 auto-create-brand feature guards against creating a brand that already exists — but only
by EXACT (case-insensitive) name match (`findExistingId` looped `allBrands()` comparing lowercased
names). Feed manufacturers are frequently more specific than the clean Woo brand
(`'Barco Clickshare'` vs `'Barco'`, `'HP Inc'` vs `'HP'`, `'Sony Professional'` vs `'Sony'`), so on the
bulk/auto-create path WooBrandCreator would POST a near-duplicate brand next to the clean one — exactly
the taxonomy pollution the 260702-om7 brand cleanup fixed. The per-row approve path already avoided this
because it fuzzy-matches via `TaxonomyResolver::resolveBrand` first (no WooBrandCreator call for
`'Barco Clickshare'`).

## The Fix: One Method

`WooBrandCreator::findExistingId` swapped from an exact `allBrands()` loop to a delegation to the fuzzy
resolver:

```php
private function findExistingId(string $name): ?int
{
    $id = $this->taxonomy->resolveBrand($name);

    return ($id !== null && $id > 0) ? $id : null;
}
```

`resolveBrand` normalises the name and runs `bestMatchId` over the cached `allBrands()` list.
Containment (`'barco' ⊂ 'barco clickshare'`) scores 0.9 ≥ 0.85 so it matches and returns the existing
`'Barco'` id; genuinely-different names (`'Trantec'`) stay below the threshold, return null, and are
created via POST exactly as before.

Everything else in `ensureBrandTermId` is untouched: normalise → junk/blank → null; existing hit →
return id (no POST); else POST + shadow-guard + cache-forget + return new id; `term_exists` → forget +
re-lookup. No signature change. The method still never throws.

Both create paths now converge on the same brand identity — the per-row approve already fuzzy-matched
upstream, and the bulk/auto-create path now reuses `'Barco'` via WooBrandCreator instead of minting
`'Barco Clickshare'`.

## Tests

Rewrote `tests/Unit/ProductAutoCreate/WooBrandCreatorTest.php` to exercise the REAL fuzzy matcher: it
builds a real `TaxonomyResolver` from the container and seeds the brand list the resolver reads via
`Cache::put('taxonomy.brands', [['id'=>10,'name'=>'Barco'],['id'=>11,'name'=>'Yealink']], 3600)` — the
only mock is the WooClient (asserting `post()` call-count):

- `'Barco Clickshare'` → returns **10** (existing Barco), `post()` NOT called — the core fix.
- `'Yealink'` → returns **11**, `post()` NOT called (exact match unchanged).
- `'Trantec'` → `resolveBrand` null → `post('products/brands', {name:'Trantec'})` called once → returns
  the new id; `taxonomy.brands` cache forgotten.
- junk `'Specials'` → null, `post()` NOT called.
- Preserved: blank/whitespace/null → null; HTML-entity name (`VOGEL&#039;S` → `VOGEL'S`); `term_exists`
  → success re-read (99); shadow mode → null; non-term-exists error → null (never throws).

RED-confirmed first (Barco case failed: `null` ≠ `10`), then GREEN after the one-method change.

## Verification

- `pest tests/Unit/ProductAutoCreate/WooBrandCreatorTest.php` → **9 passed (21 assertions)**.
- Regression `pest tests/Feature/ProductAutoCreate/CreateWooProductJobBrandTest.php
  tests/Feature/Console/DraftFromSuggestionsCreateBrandTest.php` → **8 passed (29 assertions)** —
  Trantec still creates; the qd8 wiring is unaffected (both feature suites mock WooBrandCreator whole,
  so only the internal existence lookup changed).
- `pint --test app/Domain/ProductAutoCreate/Services/WooBrandCreator.php` → **PASS**.

## Deviations from Plan

None — plan executed exactly as written (RED → GREEN, no refactor needed).

## Operator Notes

- **Deploy:** push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no
  migration). NOT pushed / NOT deployed by this task — local commits only.
- Now a feed manufacturer that's a more-specific variant of an existing brand (`'Barco Clickshare'` →
  `'Barco'`, `'HP Inc'` → `'HP'`) reuses the existing Woo brand instead of creating a near-duplicate —
  on BOTH create paths.
- **Threshold** is 0.85 (`TaxonomyResolver::FUZZY_THRESHOLD`); containment (existing brand is a leading
  substring) scores 0.9 so it matches. Genuinely different names (Trantec, Sonos-vs-Sony) stay below and
  still create.
- **KNOWN edge (unchanged, pre-existing `bestMatchId` behaviour):** a SHORTER new brand while a LONGER
  one exists (new `'Sony'` while `'Sony Professional'` exists) reuses the longer existing term rather
  than creating the parent — rare; fix by adding the parent brand manually if needed.
- **Follow-up (out of scope):** the bulk gate's classifier (`resolveBrandKey`, exact + `' - '` only)
  still labels `'Barco Clickshare'` as `brand_not_on_woo`, so it needs `--create-missing-brands` to
  pass — but it now reuses `'Barco'` (no duplicate) rather than creating one. Making the gate itself
  fuzzy (so such SKUs aren't flagged `brand_not_on_woo` at all) is a separate optional follow-up.

## Commits

- `badefa3` — test(260703-c8q): add failing fuzzy-reuse case for WooBrandCreator (RED)
- `38c3a35` — feat(260703-c8q): WooBrandCreator existence check uses fuzzy resolveBrand (GREEN)

## Self-Check: PASSED
