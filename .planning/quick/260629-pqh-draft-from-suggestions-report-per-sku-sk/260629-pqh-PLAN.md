---
phase: 260629-pqh-draft-from-suggestions-report-per-sku-sk
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/DraftFromSuggestionsCommand.php
  - tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php
autonomous: true
requirements:
  - QUICK-260629-pqh
must_haves:
  truths:
    - "products:draft-from-suggestions reports WHY each non-candidate SKU was skipped, in three buckets: 'not sourceable' (no supplier feed row at all), 'no manufacturer' (feed row exists but manufacturer is blank), and 'brand not on Woo' (manufacturer present but not a matching Woo brand — lists the manufacturer name + SKU so the operator knows which brand to add)."
    - "When the run produces zero candidates it no longer prints only 'No matching SKUs to draft' — it prints the per-bucket skip breakdown so the operator sees the actionable reason (e.g. 'brand \"Trantec\" not on Woo — add under Products → Brands')."
    - "The skip breakdown is printed for BOTH the --skus explicit path and the pending-Suggestion-walk path; on the explicit path it lists each skipped SKU + reason (operator picked them, wants per-SKU detail), on the walk path it prints per-bucket counts (could be thousands)."
    - "Categorisation is driven by a pure, unit-tested helper classifySkip(bool $inFeed, bool $hasManufacturer, bool $brandResolved): ?string returning 'not_sourceable' | 'no_manufacturer' | 'brand_not_on_woo' | null (null = it's a candidate, not skipped)."
    - "No change to WHICH SKUs become candidates — the existing filter/resolveBrandKey logic is unchanged; this only ADDS reporting. Candidates still draft+publish exactly as today."
  artifacts:
    - path: "app/Console/Commands/DraftFromSuggestionsCommand.php"
      provides: "Skip-reason buckets collected in the chunk processor + printed breakdown; classifySkip() helper"
      contains: "classifySkip"
    - path: "tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php"
      provides: "Unit coverage of classifySkip() across all four outcomes"
      contains: "brand_not_on_woo"
  key_links:
    - from: "DraftFromSuggestionsCommand chunk processor"
      to: "skip buckets + classifySkip()"
      via: "record a reason for every SKU that does not become a candidate"
      pattern: "classifySkip"
---

<objective>
Stop products:draft-from-suggestions from failing silently. Today, any SKU that isn't a candidate is
dropped with no explanation and the run ends with just "No matching SKUs to draft" — so operators see
"N SKU(s) queued" then nothing, with no idea why (confirmed repeatedly 2026-06-28/29: not-sourceable
SKUs like A75DM66D, and sourceable-but-brand-not-on-Woo SKUs like S4.04-B-EB-GD5 / Trantec).

Add per-SKU skip reporting in three actionable buckets:
  - not sourceable     — no supplier feed row for the SKU (only competitors carry it)
  - no manufacturer    — feed row exists but manufacturer is blank
  - brand not on Woo    — manufacturer present but not a Woo brand (lists "Manufacturer (SKU)" so the
                          operator knows exactly which brand to add under Products → Brands)

REPORTING ONLY — does not change which SKUs become candidates (the resolveBrandKey filter from
260628-b9t is unchanged). This is the safe half of the operator's "auto-create + report" request;
the auto-create-missing-brand half is deferred (it needs a brand-taxonomy decision: product_brand vs
WC-native products/brands are out of sync — see [[meetingstore-brand-display]]).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260629-pqh-draft-from-suggestions-report-per-sku-sk/
@CLAUDE.md
@app/Console/Commands/DraftFromSuggestionsCommand.php

<interfaces>
CURRENT chunk processor (DraftFromSuggestionsCommand.php ~133-186). Today it builds $supMap from
feeds_products rows that have a NON-EMPTY manufacturer (the `if ($mfr === '') continue;` at ~157
means blank-manufacturer rows never enter $supMap). To distinguish "not in feed at all" from "in
feed but blank manufacturer", ALSO record which SKUs matched ANY feed row.

Inside the SQL fetch loop (~155-162), additionally collect a $seenInFeed set keyed by lowered
suppliersku AND mpn for EVERY returned row (regardless of manufacturer), e.g.:
```php
$seenInFeed[strtolower((string) $r['suppliersku'])] = true;
$seenInFeed[strtolower((string) $r['mpn'])] = true;
```
(keep the existing $supMap population, which still only holds non-empty manufacturers.)

In the per-SKU loop (~165-183), every `continue` becomes a recorded skip. Replace the bare skips with:
```php
$key = strtolower($sku);
$inFeed = isset($seenInFeed[$key]);
$hasMfr = isset($supMap[$key]);
$brandKey = $hasMfr ? $this->resolveBrandKey(mb_strtolower($supMap[$key]), $wooBrandsByLower) : null;
$reason = $this->classifySkip($inFeed, $hasMfr, $brandKey !== null);
if ($reason !== null) {
    // record into $skips bucket then continue
    if ($reason === 'brand_not_on_woo') {
        $skips['brand_not_on_woo'][] = $supMap[$key].' ('.$sku.')';   // manufacturer + sku
    } else {
        $skips[$reason][] = $sku;
    }
    continue;
}
// candidate (reason === null): proceed exactly as today using $brandKey
if ($brandsFilter !== null && ! in_array($brandKey, $brandsFilter, true)) {
    $skips['brand_filtered'][] = $sku; // optional; --brands explicitly excluded
    continue;
}
$canonical = $wooBrandsByLower[$brandKey];
$candidates[$sku] = $canonical;
$byBrand[$canonical][] = $sku;
```
$skips must be declared in the OUTER scope (alongside $candidates/$byBrand) and `use (&$skips)` in the
closure, so it survives across chunks.

NEW pure helper (public, unit-tested):
```php
/**
 * Why was a SKU skipped (or null if it's a valid candidate)?
 *   not_sourceable   — no supplier feed row at all
 *   no_manufacturer  — feed row exists but manufacturer blank
 *   brand_not_on_woo — manufacturer present but not a Woo brand
 */
public function classifySkip(bool $inFeed, bool $hasManufacturer, bool $brandResolved): ?string
{
    if (! $inFeed) { return 'not_sourceable'; }
    if (! $hasManufacturer) { return 'no_manufacturer'; }
    if (! $brandResolved) { return 'brand_not_on_woo'; }
    return null;
}
```

PRINTING — after the existing "Batch: N product(s)" summary block (and ALSO when $count === 0, before
the current early `return SUCCESS`), print the skip breakdown:
```php
$totalSkipped = array_sum(array_map('count', $skips));
if ($totalSkipped > 0) {
    $this->newLine();
    $this->warn("Skipped {$totalSkipped} SKU(s):");
    if (! empty($skips['not_sourceable']))   { $this->line('  not sourceable (no supplier carries it): '.count($skips['not_sourceable'])); }
    if (! empty($skips['no_manufacturer']))  { $this->line('  no manufacturer in feed: '.count($skips['no_manufacturer'])); }
    if (! empty($skips['brand_not_on_woo'])) { $this->line('  brand not on Woo (add under Products → Brands): '.count($skips['brand_not_on_woo'])); }
    // Explicit --skus path: list each skipped SKU + reason (operator picked them).
    if ($explicitSkus !== []) {
        foreach (['not_sourceable' => 'not sourceable', 'no_manufacturer' => 'no manufacturer', 'brand_not_on_woo' => 'brand not on Woo'] as $bk => $label) {
            foreach ($skips[$bk] ?? [] as $entry) { $this->line("    - {$entry}: {$label}"); }
        }
    }
}
```
Initialise `$skips = ['not_sourceable'=>[], 'no_manufacturer'=>[], 'brand_not_on_woo'=>[], 'brand_filtered'=>[]];`
near $candidates.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: classifySkip() helper + unit test (RED→GREEN)</name>
  <files>
    app/Console/Commands/DraftFromSuggestionsCommand.php,
    tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php
  </files>
  <behavior>
    Add the public classifySkip() helper (per <interfaces>). Unit test all four outcomes:
      - classifySkip(false, false, false) === 'not_sourceable'
      - classifySkip(false, true, true)  === 'not_sourceable'  (inFeed false dominates)
      - classifySkip(true, false, false) === 'no_manufacturer'
      - classifySkip(true, true, false)  === 'brand_not_on_woo'
      - classifySkip(true, true, true)   === null  (candidate)
    Construct via app(DraftFromSuggestionsCommand::class); no DB touched.
  </behavior>
  <action>
    Add classifySkip() near the other helpers (parseBrandsFilter/parseSkusOption). Write the unit test.
    Run it + pint. (No wiring yet in this task — pure helper first.)
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php 2>&1 | tail -15</automated>
    Expected: GREEN.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/DraftFromSuggestionsCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - classifySkip() exists; 5 cases GREEN; pint clean.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: wire skip buckets into the chunk processor + print breakdown</name>
  <files>
    app/Console/Commands/DraftFromSuggestionsCommand.php
  </files>
  <behavior>
    The chunk processor records a skip reason (via classifySkip) for every SKU that doesn't become a
    candidate, into an outer-scope $skips array (4 buckets). The SQL fetch loop also builds $seenInFeed
    (all matched suppliersku/mpn, regardless of manufacturer) so not_sourceable vs no_manufacturer can
    be distinguished. After the batch summary — and also on the zero-candidate early return — the
    command prints the skip breakdown (per-bucket counts always; per-SKU lines on the --skus path).
    Candidate selection is byte-identical to today (resolveBrandKey unchanged); only reporting is added.
  </behavior>
  <action>
    Implement exactly per <interfaces>: declare/initialise $skips alongside $candidates/$byBrand; add
    `use (&$skips)` to the $chunkProcessor closure signature; build $seenInFeed in the fetch loop;
    replace the bare `continue` skips with classifySkip-driven bucket recording; add the brandsFilter
    skip bucket; print the breakdown after the "Batch:" summary AND in the `if ($count === 0)` branch
    (before its `return SymfonyCommand::SUCCESS`). Run pint.

    Manual sanity (document in SUMMARY, not executed here): on prod after deploy,
    `--skus=A75DM66D,S4.04-B-EB-GD5 --dry-run` should now print:
      Skipped 2 SKU(s):
        not sourceable (no supplier carries it): 1
        brand not on Woo (add under Products → Brands): 1
          - A75DM66D: not sourceable
          - Trantec (S4.04-B-EB-GD5): brand not on Woo
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/DraftFromSuggestionsCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
    <automated>grep -nE "classifySkip|seenInFeed|\\\$skips" app/Console/Commands/DraftFromSuggestionsCommand.php | head</automated>
    Expected: $skips initialised + used in closure (use (&$skips)), $seenInFeed built in fetch loop, classifySkip called in per-SKU loop, breakdown printed.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Console/ 2>&1 | tail -15</automated>
    Expected: existing Console feature tests still GREEN (no behavioural regression to candidate selection / drafting).
  </verify>
  <done>
    - Skip buckets collected across chunks; breakdown printed after summary and on zero-candidate path; per-SKU detail on --skus path.
    - Candidate selection unchanged; existing Console tests GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php` → GREEN
2. `pest tests/Feature/Console/` → GREEN (no regression)
3. `pint --test app/Console/Commands/DraftFromSuggestionsCommand.php` → PASS
4. Logic review: classifySkip null path = candidate, unchanged selection.

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Verify: `php artisan products:draft-from-suggestions --skus=A75DM66D,S4.04-B-EB-GD5 --dry-run` now prints the skip breakdown with reasons (not sourceable; brand "Trantec" not on Woo).
- Deferred follow-up: auto-create the missing brand — needs the product_brand vs WC-native products/brands taxonomy-source decision first (do with a dry-run; don't re-pollute brands).
</verification>

<success_criteria>
- Every skipped SKU is reported with an actionable reason; zero-candidate runs explain why instead of just "No matching SKUs to draft".
- --skus path lists each skipped SKU + reason; walk path prints per-bucket counts.
- classifySkip() pure + unit-tested; candidate selection unchanged; pint clean; no Console-test regressions.
</success_criteria>

<output>
Create `.planning/quick/260629-pqh-draft-from-suggestions-report-per-sku-sk/260629-pqh-SUMMARY.md` documenting:
- The silent-skip problem + the three actionable buckets now reported.
- classifySkip() + the $seenInFeed addition to distinguish not-sourceable from no-manufacturer.
- That this is reporting-only (candidate selection unchanged) and the auto-create-brand half is deferred pending the brand-taxonomy-source decision (product_brand vs products/brands).
- Operator verify steps.
</output>
