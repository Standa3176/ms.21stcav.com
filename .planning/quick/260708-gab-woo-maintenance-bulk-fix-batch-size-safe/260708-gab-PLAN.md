---
phase: 260708-gab-woo-maintenance-bulk-fix-batch-size-safe
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - config/services.php
  - app/Filament/Pages/CatalogueGapsPage.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
  - tests/Feature/Products/CatalogueGapsPageTest.php
must_haves:
  truths:
    - "The Catalogue Gaps BULK fix actions (Source images / Backfill EAN / Resync) cap how many SKUs they process in one run to config('services.woo.maintenance_fix_batch_limit', 25). If the operator selects MORE than the limit, only the first N are dispatched and a notification says '(capped at N of M selected — run again for the rest)'. This prevents an accidental 600-SKU Source-images/Backfill-EAN run (money-costing API calls + a 30s web timeout)."
    - "The cap applies ONLY to the bulk actions; per-row fix actions (single SKU) are unchanged. The command still runs via Artisan::call(['--skus' => csv]) synchronously — just bounded."
    - "The Suggestions 'Comp' column label is renamed to the clearer 'Competitor count' (the count of competitors tracking the SKU), keeping its existing tooltip; the separate 'Competitors' (names) column is unchanged — no label clash."
    - "Additive/UX — no change to which products are gappy, the reconciliation, the row actions' behaviour, or any other column/filter."
  artifacts:
    - path: "app/Filament/Pages/CatalogueGapsPage.php"
      provides: "bulk fix actions capped at the config batch limit + cap notice"
      contains: "maintenance_fix_batch_limit"
    - path: "config/services.php"
      provides: "woo.maintenance_fix_batch_limit (default 25)"
      contains: "maintenance_fix_batch_limit"
    - path: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php"
      provides: "'Comp' -> 'Competitor count' label"
      contains: "Competitor count"
  key_links:
    - from: "CatalogueGapsPage::bulkFixAction()"
      to: "config('services.woo.maintenance_fix_batch_limit', 25)"
      via: "take(limit) on the selected SKUs before Artisan::call"
      pattern: "maintenance_fix_batch_limit"
---

<objective>
Safety rail + a small clarity fix. (A) Cap the Catalogue Gaps BULK fix actions to a config batch limit so
nobody accidentally fires hundreds of money-costing Source-images / Backfill-EAN calls (or times out the
request) in one click. (C) Rename the cryptic Suggestions 'Comp' column to 'Competitor count'.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-gab-woo-maintenance-bulk-fix-batch-size-safe/
@CLAUDE.md
@app/Filament/Pages/CatalogueGapsPage.php
@config/services.php
@app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
@tests/Feature/Products/CatalogueGapsPageTest.php
---
CatalogueGapsPage::bulkFixAction() currently: `$csv = $records->pluck('sku')->filter(...)->implode(',')`
then `Artisan::call($command, ['--skus' => $csv])` — NO cap. config/services.php has a 'woo' => [...] array
(write_enabled at ~line 50) to extend. SuggestionResource line ~267: the count column
`TextColumn::make('supporting_competitors')->label('Comp')` (the names column is make('competitors')->label('Competitors') — leave it).
</context>

<interfaces>
=== config/services.php (inside the 'woo' => [...] array) ===
```php
// 260708-gab — max products a single Catalogue Gaps BULK fix action processes (Source images / Backfill
// EAN cost per-SKU API calls; cap protects against an accidental huge run + a 30s web timeout).
'maintenance_fix_batch_limit' => (int) env('WOO_MAINTENANCE_FIX_BATCH_LIMIT', 25),
```

=== CatalogueGapsPage::bulkFixAction() — cap the selection ===
Replace the CSV build + dispatch with a capped version:
```php
->action(function (Collection $records) use ($command, $label): void {
    $limit = max(1, (int) config('services.woo.maintenance_fix_batch_limit', 25));
    $skus = $records->pluck('sku')->filter(fn ($s): bool => $s !== null && $s !== '')->values();
    $selected = $skus->count();
    $batch = $skus->take($limit);
    $csv = $batch->implode(',');

    if ($csv === '') {
        Notification::make()->warning()->title('No SKUs in the selection')->send();
        return;
    }
    try {
        Log::info('CatalogueGapsPage: bulk fix action invoked', [
            'command' => $command, 'skus' => $csv, 'dispatched' => $batch->count(),
            'selected' => $selected, 'limit' => $limit, 'actor_id' => auth()->id(),
        ]);
        Artisan::call($command, ['--skus' => $csv]);

        $title = "{$label} dispatched for ".$batch->count().' product(s)';
        if ($selected > $limit) {
            $title .= " (capped at {$limit} of {$selected} selected — run again for the rest)";
        }
        Notification::make()->success()->title($title)->send();
    } catch (\Throwable $e) {
        Notification::make()->danger()->title("{$label} failed")->body($e->getMessage())->send();
    }
})
```
Also add a `->modalDescription(...)` note that bulk runs are capped at the limit (optional but helpful).
Per-row fixAction() is UNCHANGED.

=== SuggestionResource (C) ===
Line ~267: change `->label('Comp')` to `->label('Competitor count')` on the `supporting_competitors` column.
Keep its existing tooltip + badge/sortable. Do NOT touch the `competitors` (names) column.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: cap bulk fix actions + rename Comp column</name>
  <files>
    config/services.php,
    app/Filament/Pages/CatalogueGapsPage.php,
    app/Domain/Suggestions/Filament/Resources/SuggestionResource.php,
    tests/Feature/Products/CatalogueGapsPageTest.php
  </files>
  <behavior>
    Add the config key + cap the bulkFixAction + rename the Comp label per <interfaces>. Extend
    CatalogueGapsPageTest: with config('services.woo.maintenance_fix_batch_limit') low (e.g. set to 2 via
    config()->set in the test), seed 4 gap products, select all 4, invoke a bulk fix action, and assert the
    Artisan command was called with a --skus value containing exactly 2 SKUs (Artisan::fake / spy) — i.e. the
    cap is enforced. Also a selection within the limit passes all SKUs. Keep the existing CatalogueGaps tests
    (filter + per-row fix action + missing_brand) green.
    (C is a label-only change — no test; the existing SuggestionResource tests must stay green.)
  </behavior>
  <action>
    Add config key; cap bulkFixAction; rename the Comp label. Extend the test. Run CatalogueGapsPageTest +
    a SuggestionResource smoke (readiness/filter tests) + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/CatalogueGapsPageTest.php 2>&1 | tail -18</automated>
    Expected: GREEN — bulk fix caps to the limit; existing filter/fix/brand cases still pass.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Suggestions/SuggestionReadinessFilterTest.php 2>&1 | tail -6</automated>
    Expected: GREEN (Comp label change doesn't break the resource).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test config/services.php app/Filament/Pages/CatalogueGapsPage.php app/Domain/Suggestions/Filament/Resources/SuggestionResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Bulk fix actions capped at config('services.woo.maintenance_fix_batch_limit',25) with a cap notice; per-row unchanged; 'Comp' renamed to 'Competitor count'; tests green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/CatalogueGapsPageTest.php` → GREEN (cap enforced)
2. `pest tests/Feature/Suggestions/SuggestionReadinessFilterTest.php` → GREEN
3. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Catalogue Gaps bulk fixes now process at most WOO_MAINTENANCE_FIX_BATCH_LIMIT (default 25) products per
  click — if you select more, it does the first 25 and tells you to run again. Tune via that env var. Guards
  against a giant Source-images / Backfill-EAN run (API cost + 30s timeout). Per-row fixes unchanged.
- Suggestions 'Comp' column is now 'Competitor count' (clearer).
</verification>

<success_criteria>
- Catalogue Gaps bulk fix actions are capped at the config batch limit with a clear cap notice (per-row unchanged); the Suggestions Comp column reads 'Competitor count'; cap enforcement tested; existing tests green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-gab-woo-maintenance-bulk-fix-batch-size-safe/260708-gab-SUMMARY.md` documenting
the bulk-fix batch cap (config + notice), the Comp->Competitor count rename, the cap test, and the deploy note.
</output>
