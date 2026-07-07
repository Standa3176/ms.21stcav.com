---
phase: 260707-gsy-make-suggestions-obvious-tier-1-add-a-re
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
  - tests/Unit/Suggestions/SuggestionReadinessTest.php
  - tests/Feature/Suggestions/SuggestionReadinessColumnTest.php
must_haves:
  truths:
    - "The Suggestions list shows a Readiness badge for new_product_opportunity rows so the operator can see, BEFORE clicking Auto-create, whether a row will actually create: 'Ready' (green), 'Needs brand' (amber), 'Not sourceable' (gray). Non-opportunity kinds show '—'. This is the fix for the recurring 'I select rows, hit Auto-create, and nothing happens with no reason' confusion."
    - "Readiness is computed engine-independently: sourceable = the SKU (from evidence.sku, extracted in PHP) EXISTS in supplier_sku_cache via a plain indexed where()->exists() (NO JSON-in-SQL — dodges the SQLite/MariaDB trap); brand comes from evidence.brand; 'Needs brand' = sourceable but brand blank or on the junk list (config product_auto_create.brands_to_add_exclude); 'Ready' = sourceable + usable brand (Auto-create auto-creates the Woo brand if new, per 260702-qd8). Pure readinessFrom(bool,?string) is unit-tested; per-record result is memoised (1 cache lookup per row)."
    - "The list DEFAULTS to the actionable set: Status filter default 'pending' + Kind filter default 'new_product_opportunity' — so the operator lands on pending new-product opportunities (still freely changeable). No change to getEloquentQuery's existing guardrail-hiding."
    - "Feedback after Auto-create: the table polls (auto-refreshes) so rows flip to 'applied' as the Horizon pipeline finishes, and the bulk-action success notification body explicitly tells the operator rows will update here + points to Auto-create Health + the bell — instead of going silent."
    - "Additive/display-only + defaults: existing columns, the SKU/competitor/price columns, the resolve/reject/replay actions, the Auto-create bulk action's behaviour (still dispatches RunAutoCreatePipelineJob), and all other filters are unchanged."
  artifacts:
    - path: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php"
      provides: "Readiness column + pure readinessFrom + memoised readiness + default filters + table poll + richer dispatch notification"
      contains: "readinessFrom"
    - path: "tests/Unit/Suggestions/SuggestionReadinessTest.php"
      provides: "pure readinessFrom unit tests"
      contains: "readinessFrom"
    - path: "tests/Feature/Suggestions/SuggestionReadinessColumnTest.php"
      provides: "readiness() with supplier_sku_cache + kind gating"
      contains: "supplier_sku_cache"
  key_links:
    - from: "Readiness TextColumn"
      to: "readiness(record) → readinessFrom(sourceable, brand)"
      via: "supplier_sku_cache exists (PHP-extracted sku) + evidence.brand + junk config"
      pattern: "readinessFrom"
---

<objective>
Tier-1 "make Suggestions obvious" pass. The Suggestions list forces the operator to understand hidden
machinery (is it sourceable? is the brand on Woo?) and gives no feedback after Auto-create — the root of the
session's confusion. Add (1) a Readiness badge (Ready / Needs brand / Not sourceable) so what-will-create is
visible up front, (2) default the list to Pending + new_product_opportunity so they land on the actionable
set, and (3) feedback after Auto-create (table auto-refresh + a clearer notification). Additive.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260707-gsy-make-suggestions-obvious-tier-1-add-a-re/
@CLAUDE.md
@app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
@app/Domain/Suggestions/Models/Suggestion.php
@app/Filament/Pages/AutoCreateHealthPage.php
@config/product_auto_create.php
---
NOTE the SQLite↔MariaDB trap: compute `sourceable` with a plain Eloquent `where('sku', $skuLower)->exists()`
on supplier_sku_cache (SKU pulled from evidence in PHP) — do NOT add JSON_UNQUOTE/JSON_EXTRACT SQL for the
readiness column.
</context>

<interfaces>
=== SuggestionResource — add helpers ===
```php
use Illuminate\Support\Facades\DB;                 // if not already imported
use App\Filament\Pages\AutoCreateHealthPage;        // for the notification link

/** Per-request memo so the column's state/color/tooltip closures don't each re-query. */
protected static array $readinessMemo = [];

/**
 * PURE readiness verdict. sourceable = SKU is in the supplier feed; brand from
 * evidence.brand. 'Ready' = sourceable + usable brand (Auto-create auto-adds the
 * Woo brand if new); 'Needs brand' = sourceable but blank/junk brand; else 'Not
 * sourceable'. @return array{label:string,color:string}
 */
public static function readinessFrom(bool $sourceable, ?string $brand): array
{
    if (! $sourceable) {
        return ['label' => 'Not sourceable', 'color' => 'gray'];
    }
    $brand = trim((string) $brand);
    $junk = $brand === '' || in_array(
        mb_strtolower($brand),
        array_map('mb_strtolower', (array) config('product_auto_create.brands_to_add_exclude', [])),
        true,
    );
    return $junk
        ? ['label' => 'Needs brand', 'color' => 'warning']
        : ['label' => 'Ready', 'color' => 'success'];
}

/** Readiness for a record (null for non-opportunity kinds). Memoised per request. */
public static function readiness(Suggestion $record): ?array
{
    if ($record->kind !== 'new_product_opportunity') {
        return null;
    }
    $key = (string) $record->getKey();
    if (! array_key_exists($key, self::$readinessMemo)) {
        $sku = strtolower(trim((string) data_get($record->evidence, 'sku', '')));
        $sourceable = $sku !== '' && DB::table('supplier_sku_cache')->where('sku', $sku)->exists();
        self::$readinessMemo[$key] = self::readinessFrom($sourceable, (string) data_get($record->evidence, 'brand', ''));
    }
    return self::$readinessMemo[$key];
}
```

=== Readiness column — add near the Status column ===
```php
TextColumn::make('readiness')
    ->label('Readiness')
    ->badge()
    ->state(fn (Suggestion $record): ?string => self::readiness($record)['label'] ?? null)
    ->color(fn (Suggestion $record): string => self::readiness($record)['color'] ?? 'gray')
    ->placeholder('—')
    ->tooltip(fn (Suggestion $record): ?string => match (self::readiness($record)['label'] ?? null) {
        'Ready' => 'In the supplier feed + brand known — Auto-create will create it (brand auto-added if new).',
        'Needs brand' => 'In the supplier feed but no usable brand — parks until a brand is set.',
        'Not sourceable' => 'No supplier currently carries this SKU — cannot be created.',
        default => null,
    }),
```

=== Default filters (land on the actionable set) ===
On the existing Status SelectFilter add `->default('pending')`; on the Kind SelectFilter add
`->default('new_product_opportunity')`. (Leave their option lists + the guardrail-hiding getEloquentQuery
untouched — defaults are just the initial selection.)

=== Feedback ===
1. On the table (in `table(Table $table)`), add `->poll('30s')` so the list auto-refreshes and rows flip to
   'applied' as the pipeline finishes. (Read-only refresh; safe.)
2. In the `auto_create_full` bulk action's success Notification (the "{n} SKU(s) queued" one), replace the
   body with something that sets expectations + links onward, e.g.:
   `->body('Dispatched. These rows will change to "applied" here as each finishes (this list refreshes itself), and the new products appear under Auto-create Health. The full created/skipped result lands in your notifications bell — usually under a minute.')`
   Keep the title ("{n} SKU(s) queued") and the dispatch call unchanged.
Do NOT change the Readiness of the "No eligible rows" warning path, the toggles, or the dispatch args.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Readiness column + default view + Auto-create feedback</name>
  <files>
    app/Domain/Suggestions/Filament/Resources/SuggestionResource.php,
    tests/Unit/Suggestions/SuggestionReadinessTest.php,
    tests/Feature/Suggestions/SuggestionReadinessColumnTest.php
  </files>
  <behavior>
    Add readinessFrom + readiness + the Readiness column + default filters + ->poll('30s') + the richer
    bulk-action notification body, per <interfaces>.
    Unit test (pure readinessFrom): (true,'Barco')→Ready/success; (true,'')→Needs brand/warning;
      (true,'Specials')→Needs brand (junk from config); (true,'Un-Branded')→Needs brand;
      (false,'Barco')→Not sourceable/gray.
    Feature test (DB): seed supplier_sku_cache with a lowercased sku; a pending new_product_opportunity
      Suggestion whose evidence.sku matches + evidence.brand='Barco' → readiness()==Ready; same SKU absent
      from cache → Not sourceable; brand '' → Needs brand; a margin_change kind → readiness()===null.
      (Reset self::$readinessMemo between assertions, or use distinct records.)
    Keep existing SuggestionResource tests (BrandFilter, BrandsToAddIndex, etc.) green.
  </behavior>
  <action>
    Edit SuggestionResource (helpers, column, defaults, poll, notification body). Write both tests. Run them
    + the existing Suggestions tests + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Suggestions/SuggestionReadinessTest.php tests/Feature/Suggestions/SuggestionReadinessColumnTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (pure verdicts + DB-backed readiness incl. kind gating + junk brand).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Suggestions tests/Unit/Console/BrandsToAddIndexTest.php 2>&1 | tail -10</automated>
    Expected: existing Suggestions tests still GREEN.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Suggestions/Filament/Resources/SuggestionResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Readiness badge (Ready/Needs brand/Not sourceable) renders for opportunities, '—' for other kinds; list defaults to Pending + new_product_opportunity; table polls + the Auto-create notification explains what happens next; pure + DB tests green; existing Suggestions tests green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest` readiness unit + feature → GREEN
2. existing Suggestions tests → GREEN
3. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Suggestions now opens on Pending · New products with a **Readiness** column: **Ready** = will create
  (bulk Auto-create is safe on these), **Needs brand** = in the feed but no usable brand (parks), **Not
  sourceable** = no supplier carries it (can't create). After Auto-create the list auto-refreshes so rows
  flip to *applied*, and the toast points to Auto-create Health + the bell.
- Tier-2 follow-ups (not in this task): a Readiness FILTER (show only Ready) replacing the confusing
  Brand-on-Woo ternary; trimming/grouping the 7-filter row; splitting the failure kinds (crm/auto-create/
  guardrail) into their own view; renaming 'Comp' + column tooltips + a page description.
</verification>

<success_criteria>
- Operators can see per-row whether a suggestion will create (Readiness badge) before acting, land on the actionable set by default, and get visible feedback after Auto-create; readiness is engine-independent (no JSON-in-SQL); pure + DB tests + existing Suggestions tests + pint all green; additive only.
</success_criteria>

<output>
Create `.planning/quick/260707-gsy-make-suggestions-obvious-tier-1-add-a-re/260707-gsy-SUMMARY.md` documenting
the Readiness badge (verdict rules + engine-independent sourceable check), the default-to-actionable filters,
the poll + richer notification feedback, the tests, and the Tier-2 follow-up list.
</output>
