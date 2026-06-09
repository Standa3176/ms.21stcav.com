---
quick_id: 260609-rie
type: quick
mode: execute
created: 2026-06-09
description: LEFT JOIN supplier_db.stockseparate when feeds.is_stock_separate=1 — fix Ingram silently zeroing 124k in-stock SKUs across SupplierDbSyncCommand + ExplainSupplierCostCommand. Also installs an architecture-test regression guard so the dual-file gap cannot be silently reintroduced anywhere under app/Domain/Sync/ or app/Console/Commands/.

# ─── Scope decision (probed 2026-06-09, see Task 1) ───────────────────────────
# All 10 grep hits for `FROM feeds_products` inventoried. Only sites that read
# `.stock` need the LEFT JOIN. Others read EAN / manufacturer / mpn / suppliersku
# only and are unaffected by the dual-file architecture.
#
# IN SCOPE (3 sites — all read fp.stock):
#   - app/Domain/Sync/Commands/SupplierDbSyncCommand.php          (lines 139–144)
#   - app/Domain/Sync/Commands/SupplierDbSyncCommand.php          (lines 392–398)
#   - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php     (lines 72–77)
#
# OUT OF SCOPE — read feeds_products WITHOUT touching `.stock` (verified by
# inspecting each SELECT list). Documented in SUMMARY so future engineers know
# we considered them and decided no fix is needed:
#   - app/Console/Commands/BackfillMerchantFeedCommand.php:881  → SELECT suppliersku, ean
#   - app/Console/Commands/BackfillMerchantFeedCommand.php:905  → SELECT mpn, ean
#   - app/Console/Commands/BackfillMerchantFeedCommand.php:966  → SELECT suppliersku, manufacturer
#   - app/Console/Commands/BackfillMerchantFeedCommand.php:990  → SELECT mpn, manufacturer
#   - app/Domain/Sync/Services/SupplierSkuRegistry.php:52        → SELECT mpn_key, ssku_key (registry only)
#   - app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php:70 → SELECT mpn_key, ssku_key (existence check)
#   - app/Domain/Sync/Services/SupplierAddCandidateScanner.php:61 → SELECT mpn, manufacturer, title, supplierskus (no stock)
#
# IMPORTANT DEVIATION FROM TASK BRIEF: the BackfillMerchantFeedCommand was
# listed in the brief as needing 4 fixes. Grep + read of the actual SELECT
# lists shows none of its 4 SQL sites read `.stock` — they read EAN and
# manufacturer, used as forward/reverse SKU↔EAN and SKU↔manufacturer maps.
# The buy-price selection in BackfillMerchantFeedCommand goes through
# AdCandidateScanner / SupplierFreshnessResolver / the products table
# (already fed by SupplierDbSyncCommand), so fixing SupplierDbSyncCommand
# transitively fixes BackfillMerchantFeedCommand's buy-price decisions
# downstream. No direct edits in BackfillMerchantFeedCommand required.
# This is documented in PLAN Task 1's audit output and in SUMMARY.

files_modified:
  - app/Domain/Sync/Concerns/JoinsStockSeparate.php                              # NEW (Task 2)
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php                           # MODIFY (Task 3)
  - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php                      # MODIFY (Task 3)
  - tests/Architecture/StockSeparateJoinTest.php                                 # NEW (Task 4)
  - tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php                # NEW (Task 5)
  - tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php           # NEW (Task 5)

commits_expected: 5
  # T2: feat(sync) trait
  # T3: fix(sync) SupplierDbSync + ExplainSupplierCost
  # T4: test(architecture) regression guard
  # T5: test(sync) Pest coverage A–E
  # (T1 audit + T6 verify are non-commit)
---

# Quick Task 260609-rie — JOIN supplier_db.stockseparate when feeds.is_stock_separate=1

## Why this matters (one paragraph)

Probed live prod 2026-06-09: Ingram (`feeds.id=10`, `is_stock_separate=1`,
`path_to_stock_file='AVAIL/TOTUKHRL.ZIP'`) is the ONLY supplier whose stock
column on `feeds_products` is silently held at 0 for ~99% of its catalogue,
because the supplier_db architecture splits Ingram's stock to a separate
`stockseparate` table keyed by `supplier_id + suppliersku`. Sample SKU
**CP15851** (Sennheiser HA310-2EP): `feeds_products.stock=0` vs
`stockseparate.stock=5659` (matches Ingram portal). Aggregate across the
catalogue: **125,319 SKUs in-stock per stockseparate but 0 per feeds_products
— 123,901 invisible to MS**. Every "in stock" / "buy_price" / "best supplier"
decision the MS app makes is therefore wrong for those SKUs:

- `SupplierDbSyncCommand` writes `products.stock_quantity=0` for the affected
  SKUs → MS thinks they're OOS → AdCandidateScanner excludes them →
  competitor-undercut never runs → revenue leak.
- `BackfillMerchantFeedCommand` picks suboptimal buy_prices because its
  cheapest-in-stock decision (downstream of `products.buy_price` written by
  the sync command) treats the wrong rows as in-stock.
- `AuditStockDivergenceCommand` (260609-nku) — the "256 phantom SKUs" finding
  is partly contaminated by real-Ingram drop-ship SKUs misread as OOS;
  bulk-resync would HIDE customer-visible stock. Audit table is only
  trustworthy AFTER this fix lands and `supplier:db-sync` is re-run.
- Phase 7 cutover analysis assumed the legacy Stock Updater plugin was the
  bug; actual gap is the dual-file architecture nobody documented.

WestCoast (id=39) and every other supplier have `is_stock_separate=0` and
keep their stock in `feeds_products.stock` — they MUST stay byte-identical
post-fix.

The fix is a **read-side LEFT JOIN** applied identically at every fp.stock
read site, gated by `f.is_stock_separate = 1` IN THE JOIN CONDITION so
non-Ingram suppliers degrade to `fp.stock` for free. No schema change. No DB
writes. No new env var.

## The canonical SQL fragment

Every fixed site uses these two helpers (defined in Task 2's trait):

```sql
-- stockSeparateJoinClause('fp', 'ss', 'f')
LEFT JOIN feeds f ON f.id = fp.supplierid
LEFT JOIN stockseparate ss
  ON f.is_stock_separate = 1
  AND ss.supplier_id = fp.supplierid
  AND LOWER(TRIM(ss.sku)) = LOWER(TRIM(fp.suppliersku))

-- stockColumnSelect('fp', 'ss', 'f') — replaces the existing fp.stock in SELECT
COALESCE(CASE WHEN f.is_stock_separate = 1 THEN ss.stock ELSE fp.stock END, 0) AS stock
```

Invariants:
- **LEFT JOIN feeds** (not INNER) — defensive: a missing feeds row degrades
  to fp.stock instead of dropping the row entirely.
- **stockseparate JOIN gated by `f.is_stock_separate = 1` in the ON clause**
  — for is_stock_separate=0 suppliers the JOIN finds no row, CASE falls
  through to `fp.stock`, output is byte-identical to pre-fix.
- **COALESCE(..., 0)** — for is_stock_separate=1 catalogue rows WITHOUT a
  matching stockseparate row (real edge case: Ingram lists the SKU but the
  separate file hasn't shipped that part yet), output is 0 not NULL.
- **Case-insensitive + trimmed SKU match** — `feeds_products.suppliersku`
  has trailing whitespace in real prod data ("CP15851     ").
- **stockseparate.sku ↔ feeds_products.suppliersku** (NOT mpn) — confirmed
  via the probe: stockseparate.sku='CP15851' matched fp.suppliersku.

---

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
@tests/Feature/Sync/SupplierDbSyncCommandTest.php
@tests/Feature/Console/BackfillMerchantFeedCommandTest.php
@tests/Architecture/EnvUsageTest.php
</context>

<interfaces>
<!-- Key signatures the executor needs. Extracted from codebase 2026-06-09. -->

From app/Domain/Sync/Commands/SupplierDbSyncCommand.php:
```
namespace App\Domain\Sync\Commands;

final class SupplierDbSyncCommand extends BaseCommand
{
    public function __construct(
        IntegrationCredentialResolver $resolver,
        SupplierFreshnessResolver $freshness,
    );

    // Fix site 1 — line 139 (the main offers pull, feeds buildBestOfferMap)
    // Fix site 2 — line 392 (per-supplier feeds_products query, syncSupplierOfferSnapshots)

    public function buildBestOfferMap(array $supplierRows): array;
    public function parsePrice(?string $raw): ?string;
    public function parseStock(?string $raw): ?int;
}
```

From app/Domain/Sync/Commands/ExplainSupplierCostCommand.php:
```
namespace App\Domain\Sync\Commands;

final class ExplainSupplierCostCommand extends BaseCommand
{
    public function __construct(IntegrationCredentialResolver $resolver);

    // Fix site 3 — line 72 (per-SKU debug dump)
    // Signature: php artisan supplier:explain-cost {sku}
}
```

From tests/Feature/Sync/SupplierDbSyncCommandTest.php:
```
// Existing boundary strategy: helper-method coverage only (parsePrice,
// parseStock, buildBestOfferMap). mysqli connection path NOT mocked — proven
// at runtime by TestIntegrationAction smoke + live ops.
// New stockseparate tests follow the SAME boundary: they exercise the SQL
// fragments produced by the trait (string assertions) + buildBestOfferMap
// output once given a synthetic row set that mimics what the JOIN would
// return. No mysqli mocking.
```

From tests/Feature/Console/BackfillMerchantFeedCommandTest.php:
```
// Existing boundary strategy (OPTION A documented in test header): anonymous
// subclass overrides lookupSupplierEans / lookupSupplierManufacturers via
// app()->instance(). We do NOT touch BackfillMerchantFeedCommand in this
// quick — its 4 SQL sites don't read .stock (verified in Task 1).
```

From tests/Architecture/EnvUsageTest.php:
```
// Template for the regression guard: 3-assertion pattern.
//   1. Pest arch() DSL over App\ namespace (where applicable)
//   2. File-scan regex over file contents
//   3. Meta-assertion proving the regex catches a synthetic positive AND
//      rejects a synthetic negative (prevents silent regex rot)
// StockSeparateJoinTest uses assertions 2 + 3 (arch DSL can't see SQL).
```
</interfaces>

---

<tasks>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 1 — Confirm fix-site inventory + scope decision                     -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto">
  <name>Task 1: Probe + confirm exact line numbers + lock the scope decision</name>
  <files>
    No edits. Read-only audit. Output goes into the SUMMARY's "Audit table"
    section at the end of execution.
  </files>
  <action>
    Run a single grep across the four candidate paths and inspect each match
    to classify it.

    Commands (run via Bash tool):

      1. Grep all `FROM feeds_products` hits:
         pattern: `FROM feeds_products`
         case-insensitive, line numbers, glob `*.php`

      2. For each hit, Read the SELECT list to determine whether the query
         reads the `stock` column. The classification rules:
         - Reads `fp.stock` OR `, stock,` OR `, stock ` OR `SELECT stock` → IN SCOPE (needs fix)
         - Reads only `mpn` / `suppliersku` / `ean` / `manufacturer` / `title` / `supplierskus` → OUT OF SCOPE

      3. Confirm the planner's pre-baked classification (see frontmatter
         scope-decision block). If any classification turns out wrong (e.g.
         a hit selects `*` and we missed it implicitly reading .stock),
         STOP and add a remediation task before proceeding to Task 2.

      4. Probe the actual SQL strings around lines 139, 392 (SupplierDbSync)
         and 72 (ExplainSupplierCost). Confirm:
         - Both SupplierDbSync queries already alias `feeds_products fp`
           and `LEFT JOIN feeds f` — the trait's JOIN can be wedged in
           AFTER the existing `LEFT JOIN feeds f ON fp.supplierid = f.id`.
         - ExplainSupplierCost (line 72) also uses `fp` and `f` aliases —
           same wedge pattern works.

    No code change. No commit. Output: a 6-row audit table written into the
    eventual SUMMARY, plus a confirmed go/no-go for Task 2.
  </action>
  <verify>
    <automated>
      # Confirm the grep count matches the planner's inventory (10 hits as of 2026-06-09)
      grep -rn --include="*.php" "FROM feeds_products" app/ | wc -l
      # Expect: 10 (the planner's inventory). If different → STOP and re-classify.
    </automated>
  </verify>
  <done>
    Audit table written to the running plan notes:
    - 3 IN-SCOPE sites confirmed at exact lines (139, 392, 72)
    - 7 OUT-OF-SCOPE sites confirmed with selection-list justification
    - No surprise sites surfaced
    Ready to proceed to Task 2.
  </done>
</task>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 2 — Trait: App\Domain\Sync\Concerns\JoinsStockSeparate              -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto" tdd="true">
  <name>Task 2: Create JoinsStockSeparate trait (centralised SQL fragments)</name>
  <files>
    app/Domain/Sync/Concerns/JoinsStockSeparate.php (NEW)
  </files>
  <behavior>
    - `stockColumnSelect()` returns the COALESCE(CASE WHEN ...) SQL fragment
      with default aliases fp / ss / f.
    - `stockColumnSelect('a','b','c')` returns the same fragment with the
      caller's aliases substituted (so the trait can drop into queries that
      happen to alias differently).
    - `stockSeparateJoinClause()` returns the two LEFT JOIN lines (feeds + stockseparate)
      with default aliases.
    - `stockSeparateJoinClause('a','b','c')` substitutes the caller's aliases.
    - Both methods are `protected` so anyone using the trait can call them
      from inside a method but external code cannot.
    - The fragments are pure strings — no DB interaction. The trait does NOT
      need any constructor or property; it's a stateless string-builder.
    - The COALESCE wraps the CASE; the CASE picks ss.stock when
      f.is_stock_separate=1, else fp.stock; COALESCE collapses NULL → 0.
  </behavior>
  <action>
    Create the file with namespace `App\Domain\Sync\Concerns`. Implement two
    protected methods exactly as the canonical SQL fragment block above.

    Implementation notes:
    - Use `sprintf` or interpolated strings — whichever reads cleanest. The
      fragments are short enough that string concatenation is fine.
    - Include a class-level docblock that:
      (a) names the bug this trait prevents (260609-rie),
      (b) cites the live numbers (124k SKUs),
      (c) lists ALL three current consumer sites by file:line,
      (d) instructs future devs that any new `FROM feeds_products` query
          reading `.stock` MUST use these helpers.
    - The output fragment must end with `AS stock` so it's a drop-in
      replacement for the existing `fp.stock` token in the SELECT list.
    - DO NOT add `protected function stockSeparateFrom()` or other helpers
      we don't need — YAGNI. Two methods, no more.

    No env() calls (CLAUDE.md enforcement).
  </action>
  <verify>
    <automated>
      vendor/bin/pest tests/Architecture/EnvUsageTest.php
      # And smoke-load the trait to confirm it parses:
      php -r "require 'vendor/autoload.php'; class T { use App\Domain\Sync\Concerns\JoinsStockSeparate; public function expose(): array { return [\$this->stockColumnSelect(), \$this->stockSeparateJoinClause()]; } } var_dump((new T)->expose());"
      # Expect: two non-empty strings, the SELECT fragment contains 'COALESCE'
      # and 'is_stock_separate', the JOIN fragment contains 'LEFT JOIN feeds'
      # and 'LEFT JOIN stockseparate'.
    </automated>
  </verify>
  <done>
    - File exists at app/Domain/Sync/Concerns/JoinsStockSeparate.php
    - Loads via composer autoload
    - Both methods return non-empty strings with the canonical content
    - Atomic commit:
      `feat(sync): JoinsStockSeparate trait — SQL helpers for the stockseparate dual-file fix (260609-rie)`
  </done>
</task>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 3 — Wire the trait into both consumers                              -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto" tdd="true">
  <name>Task 3: Apply trait to SupplierDbSyncCommand (2 sites) + ExplainSupplierCostCommand (1 site)</name>
  <files>
    app/Domain/Sync/Commands/SupplierDbSyncCommand.php   (MODIFY — 2 SQL sites)
    app/Domain/Sync/Commands/ExplainSupplierCostCommand.php (MODIFY — 1 SQL site)
  </files>
  <behavior>
    - For each of the 3 SQL sites, the `fp.stock` token in the SELECT list is
      replaced with the output of `$this->stockColumnSelect()` so the column
      still arrives as `stock` in the fetched row.
    - The existing `LEFT JOIN feeds f ON fp.supplierid = f.id` line is
      EXTENDED so the second LEFT JOIN (stockseparate) is appended
      immediately after it via `$this->stockSeparateJoinClause()` — minus the
      `LEFT JOIN feeds` part since that already exists. (Implementation
      detail: the trait's stockSeparateJoinClause returns BOTH JOINs as a
      single string; at the call site we substitute the WHOLE existing
      `LEFT JOIN feeds f ON fp.supplierid = f.id` line with the trait
      output to keep ordering canonical and avoid duplicate-join risk.)
    - WHERE / GROUP BY / ORDER BY clauses are untouched.
    - `bind_param` calls are untouched (no new bound placeholders introduced
      by the JOIN — the JOIN condition is a literal `1`).
    - Existing fetched-row consumers (`buildBestOfferMap`,
      `syncSupplierOfferSnapshots`, the ExplainSupplierCost output table)
      continue to read `$row['stock']` and get the right value transparently.
    - WestCoast et al. (is_stock_separate=0) produce IDENTICAL output to
      pre-fix because the JOIN finds no stockseparate row and the CASE picks
      fp.stock.
    - Ingram (is_stock_separate=1) with matching stockseparate row produces
      ss.stock instead of fp.stock.
    - Ingram catalogue row with no matching stockseparate row produces 0
      (COALESCE fallback) — same as the old behaviour of "0 in fp.stock"
      from Ingram's perspective, but for the RIGHT reason.
  </behavior>
  <action>
    For each of the three files:

    1. Add `use App\Domain\Sync\Concerns\JoinsStockSeparate;` to the
       top of the file (alongside existing imports).
    2. Add `use JoinsStockSeparate;` as the first line inside the class
       body (Trait use statement, NOT the namespace import).
    3. Locate the target SQL block(s). For SupplierDbSyncCommand:
       - Lines ~139–144 (the `$sql = "SELECT fp.mpn, fp.suppliersku, ...` block
         feeding buildBestOfferMap)
       - Lines ~392–398 (the `$sql = "SELECT fp.mpn, fp.suppliersku, ...`
         block inside syncSupplierOfferSnapshots)
       For ExplainSupplierCostCommand:
       - Lines ~72–77 (`$sql = 'SELECT fp.supplierid, f.name AS supplier_name, ...`)
    4. In each block, perform two substitutions:
       (a) Replace the bare `fp.stock` token in the SELECT list with the
           expression returned by `$this->stockColumnSelect()`. The trait's
           output ends with `AS stock` so the surrounding column list works.
       (b) Replace the entire existing `LEFT JOIN feeds f ON fp.supplierid = f.id`
           line with the expression returned by `$this->stockSeparateJoinClause()`
           — which prepends the feeds JOIN it already had + appends the new
           stockseparate JOIN. ORDER MATTERS: feeds JOIN MUST come first because
           the stockseparate JOIN's ON clause references `f.is_stock_separate`.
    5. Do NOT change WHERE, ORDER BY, GROUP BY, or any bind_param sequence.
       Do NOT change column ordering in SELECT.

    Pre-flight check (run after each file's edit):
    - `grep -n "fp\.stock" {file}` → expected: 0 hits (every fp.stock got replaced)
    - `grep -n "LEFT JOIN feeds f ON fp.supplierid = f.id" {file}` → expected:
      0 hits (every old JOIN line got replaced by the trait expression)

    Coordinate verify step: run the EXISTING SupplierDbSyncCommandTest +
    BackfillMerchantFeedCommandTest. They should remain GREEN — they don't
    assert on raw SQL strings, they assert on buildBestOfferMap OUTPUT (given
    synthetic row arrays) + on the high-level CLI surface. The SQL string
    change is INVISIBLE to them.

    Commit message:
    `fix(sync): SupplierDbSyncCommand + ExplainSupplierCostCommand read stockseparate for is_stock_separate=1 (260609-rie)`
  </action>
  <verify>
    <automated>
      # Pre-flight greps must show 0 hits each:
      grep -n "fp\.stock" app/Domain/Sync/Commands/SupplierDbSyncCommand.php | grep -v "^#" | wc -l
      grep -n "fp\.stock" app/Domain/Sync/Commands/ExplainSupplierCostCommand.php | grep -v "^#" | wc -l
      # Both expect: 0

      # Existing tests must stay green (no string-level assertions on SQL):
      vendor/bin/pest tests/Feature/Sync/SupplierDbSyncCommandTest.php
      vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php

      # Architecture tests must still pass (no env() introduced):
      vendor/bin/pest tests/Architecture/EnvUsageTest.php

      # Syntax check — confirm both files parse:
      php -l app/Domain/Sync/Commands/SupplierDbSyncCommand.php
      php -l app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
    </automated>
  </verify>
  <done>
    - Both files contain `use JoinsStockSeparate;` trait usage
    - 3 SQL sites refactored — `fp.stock` no longer appears verbatim in any
      of the 3 SELECT lists; the feeds JOIN line is replaced by the trait
      expression at each site
    - Existing SupplierDbSyncCommandTest + BackfillMerchantFeedCommandTest
      GREEN (no regression)
    - php -l clean on both files
    - Atomic commit shipped:
      `fix(sync): SupplierDbSyncCommand + ExplainSupplierCostCommand read stockseparate for is_stock_separate=1 (260609-rie)`
  </done>
</task>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 4 — Architecture test (regression guard)                            -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto" tdd="true">
  <name>Task 4: Architecture test forbidding feeds_products.stock reads without JoinsStockSeparate</name>
  <files>
    tests/Architecture/StockSeparateJoinTest.php (NEW)
  </files>
  <behavior>
    - The test scans every `.php` file under app/Domain/Sync/ +
      app/Console/Commands/ + app/Domain/Pricing/Services/.
    - For each file, applies the same comment-stripping pass as
      EnvUsageTest.php (block + line comments).
    - Searches for `FROM\s+feeds_products` (case-insensitive). If a file has
      ANY such match, the file MUST satisfy AT LEAST ONE of:
      (a) References `JoinsStockSeparate` (trait import OR `use` statement),
      (b) Contains a `// stock-separate-not-applicable: <reason>` annotation
          comment somewhere in the file (escape hatch for files like
          SupplierSkuRegistry.php and SupplierFeedSourceabilityChecker.php
          that legitimately don't need .stock).
    - On failure, the error message lists every offending file:line and
      points at this plan: "Reading feeds_products without the
      stockseparate LEFT JOIN was the 260609-rie bug — see
      .planning/quick/260609-rie-*/PLAN.md and SUMMARY.md".
    - Meta-assertion (mirrors EnvUsageTest): a synthetic test proves the
      regex DOES match a positive case AND DOES NOT match a comment-only
      case after stripping — so future maintainers cannot accidentally
      weaken the regex into a no-op without breaking CI.
    - The annotation comment is added in Task 4 itself (this task's
      execution) to:
        * app/Domain/Sync/Services/SupplierSkuRegistry.php
        * app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php
        * app/Domain/Sync/Services/SupplierAddCandidateScanner.php
        * app/Console/Commands/BackfillMerchantFeedCommand.php
      so the scan passes the first time it runs. Each annotation includes
      a short reason: "selects only suppliersku/mpn/ean/manufacturer — does
      not read .stock; 260609-rie scope decision".
  </behavior>
  <action>
    1. Create tests/Architecture/StockSeparateJoinTest.php. Use the structure
       from EnvUsageTest.php as the template. Three test cases:

       (i)  `test('feeds_products.stock reads must use JoinsStockSeparate trait',
            function (): void { ... })` — the file-scan assertion.

       (ii) `test('stockseparate JOIN scan can detect raw FROM feeds_products in a
            synthetic string (meta-assertion)', function (): void { ... })` — the
            synthetic positive + comment-stripped negative test.

       (iii) `test('trait file itself defines stockSeparateJoinClause + stockColumnSelect',
             function (): void { ... })` — sanity check that the trait file at
             app/Domain/Sync/Concerns/JoinsStockSeparate.php contains both
             method names. Catches the case where someone deletes the trait
             but leaves the trait usage statements behind.

    2. The file-scan walks the three roots:
         base_path('app/Domain/Sync')
         base_path('app/Console/Commands')
         base_path('app/Domain/Pricing/Services')

       For each `.php` file:
       - Read contents.
       - Strip block comments via `preg_replace('#/\*.*?\*/#s', '', $contents)`
         and line comments via `preg_replace('#//.*$#m', ...)`. KEEP an
         unstripped copy too, so the annotation-comment check can scan it.
       - If stripped contents contain `/FROM\s+feeds_products/i`:
         - Check UNSTRIPPED contents for the annotation
           `// stock-separate-not-applicable:`. If present → OK.
         - Else check UNSTRIPPED contents for either:
             * `use App\Domain\Sync\Concerns\JoinsStockSeparate`
             * `use JoinsStockSeparate;`
           Both must appear (import + trait use). If both present → OK.
         - Else → violation; add `{relative_path}` to the violations list.
       - At the end, assert violations is empty with the descriptive
         error message naming the bug + plan path.

    3. Add the `// stock-separate-not-applicable: ...` annotation to the
       4 OUT-OF-SCOPE files listed in the behavior block above. Place the
       annotation immediately above the SQL block in each file. Wording
       template (adjust the selects-only clause per file):

         // stock-separate-not-applicable: this query selects {mpn/ean/...}
         // not stock — the 260609-rie dual-file fix only matters for reads
         // of feeds_products.stock. See PLAN scope decision.

    4. Run the test FIRST in a "should fail" state (deliberately) by NOT
       yet adding one of the annotations (e.g. omit
       SupplierFeedSourceabilityChecker's annotation) — verify the test
       FLAGS that file. Then add the annotation, re-run, watch GREEN.
       This proves the guard rail actually works, not just compiles.

       (This is dev-time only — do not commit the deliberately-broken
       state. The commit only contains the working final state.)

    5. Commit message:
       `test(architecture): forbid feeds_products.stock reads without JoinsStockSeparate (260609-rie)`
  </action>
  <verify>
    <automated>
      vendor/bin/pest tests/Architecture/StockSeparateJoinTest.php
      # Expect: 3 GREEN cases.

      # Also exercise full architecture-test slice (catches collateral regressions):
      vendor/bin/pest tests/Architecture/
    </automated>
  </verify>
  <done>
    - tests/Architecture/StockSeparateJoinTest.php created, 3/3 cases GREEN
    - 4 OUT-OF-SCOPE files carry the `// stock-separate-not-applicable:` annotation
    - 3 IN-SCOPE files carry the trait import + `use JoinsStockSeparate;`
    - Full tests/Architecture/ slice GREEN (no collateral regressions)
    - Atomic commit shipped:
      `test(architecture): forbid feeds_products.stock reads without JoinsStockSeparate (260609-rie)`
  </done>
</task>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 5 — Pest cases (A–E) for SupplierDbSyncCommand + ExplainSupplierCost -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto" tdd="true">
  <name>Task 5: Pest cases A–E covering the trait + helper-level stock resolution</name>
  <files>
    tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php  (NEW)
    tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php (NEW)
  </files>
  <behavior>
    Five test cases per file, mirroring the scope-decision invariants:

      Case A — Ingram, is_stock_separate=1, stockseparate.stock=5659 +
               feeds_products.stock=0 → resolved stock=5659 (JOIN wins)
      Case B — WestCoast, is_stock_separate=0, feeds_products.stock=42 →
               resolved stock=42 (byte-identical to pre-fix; JOIN inert)
      Case C — Ingram catalog row WITH is_stock_separate=1 but NO matching
               stockseparate row → resolved stock=0 (COALESCE fallback)
      Case D — Mixed batch: Ingram SKU + WestCoast SKU both with stock →
               both resolve to the correct value via buildBestOfferMap
      Case E — Case-insensitive + trimmed SKU match:
               stockseparate.sku='CP15851' + feeds_products.suppliersku='cp15851     '
               → still matches (the JOIN's LOWER(TRIM(...)) does its job)

    Boundary strategy (matches existing SupplierDbSyncCommandTest comment
    header — helper-method-only coverage; no live mysqli):
    - The trait produces SQL strings. Tests assert on those strings being
      well-formed (contain LEFT JOIN feeds + LEFT JOIN stockseparate +
      COALESCE + is_stock_separate = 1 + LOWER(TRIM(ss.sku))).
    - The downstream consumer of the trait's output (buildBestOfferMap) is
      tested by feeding it ROW ARRAYS that simulate what the LEFT JOIN
      would have produced. The simulation is the test's job — we trust the
      DB to honour LEFT JOIN semantics (it's MySQL 8.0, not new tech).
    - This is the SAME strategy the existing SupplierDbSyncCommandTest uses
      for its golden cases — see test header lines 10–22. Live mysqli
      coverage is proven by TestIntegrationAction + ops smoke.
  </behavior>
  <action>
    For SupplierDbSyncCommandStockSeparateTest.php:

      1. Make an anonymous subclass of SupplierDbSyncCommand that exposes
         protected trait methods publicly (so the test can call them
         without reflection):

             $cmd = new class(app(IntegrationCredentialResolver::class),
                             app(SupplierFreshnessResolver::class))
                    extends SupplierDbSyncCommand {
               public function exposeSelect(): string {
                 return $this->stockColumnSelect();
               }
               public function exposeJoin(): string {
                 return $this->stockSeparateJoinClause();
               }
             };

         Use this helper inside one test (Case A's first assertion) to
         confirm the trait is actually wired up.

      2. Case A: build a synthetic supplier-row array as if the LEFT JOIN
         returned `stock=5659` for Ingram, feed it to `buildBestOfferMap`,
         assert the resulting map's stock key is 5659 + matched_via is
         'mpn' or 'suppliersku' as appropriate.

      3. Case B: WestCoast row with stock=42 → buildBestOfferMap returns
         42. The trait's SQL invariant is also asserted: when the
         simulated row arrives with no stockseparate join, fp.stock is
         what got picked.

      4. Case C: simulated row with stock=0 (which is what COALESCE would
         produce for a missing stockseparate row) → in_stock=false, stock=0.

      5. Case D: array of two rows — one Ingram (stock=5659), one WestCoast
         (stock=42), different MPNs → map has both keys, both correct.

      6. Case E: confirm the trait's emitted SQL contains `LOWER(TRIM(ss.sku)) = LOWER(TRIM(fp.suppliersku))`
         literally — that's the contract the DB will honour at runtime, and
         it's also the only assertion we CAN make without a live DB. (The
         actual case-insensitive match is MySQL's job; we assert that the
         SQL ASKS MySQL to do it.)

    For ExplainSupplierCostCommandStockSeparateTest.php:

      1. Smaller surface — this command doesn't use buildBestOfferMap.
         Five cases assert on the SQL string emitted by `stockColumnSelect()`
         + `stockSeparateJoinClause()` (case A, C, E for SQL structure) AND
         on the literal table-render output when the command receives a
         simulated row set with mixed is_stock_separate values (cases B, D
         exercise the per-row stock column rendering, which is the only
         consumer of the resolved stock value).

      2. Use the same anonymous-subclass pattern as the SupplierDbSync test,
         but expose the `formatRow` or whatever method renders the table
         row (read ExplainSupplierCostCommand to find the right hook). If
         no clean hook exists, assert directly on the trait-emitted SQL —
         the row-render path is exercised by ops manually and is too
         tightly coupled to console output to assert cleanly.

    Commit message:
    `test(sync): stockseparate JOIN coverage cases A-E (260609-rie)`
  </action>
  <verify>
    <automated>
      vendor/bin/pest tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php
      # Expect: 5 GREEN cases (A–E)

      vendor/bin/pest tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php
      # Expect: 5 GREEN cases (A–E)

      # Regression bundle — make sure none of the existing sync/merchant-feed
      # tests broke under the SQL refactor:
      vendor/bin/pest tests/Feature/Sync/SupplierDbSyncCommandTest.php
      vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php
    </automated>
  </verify>
  <done>
    - 10 new test cases (5 per file) GREEN
    - Existing SupplierDbSyncCommandTest + BackfillMerchantFeedCommandTest
      regression bundle GREEN
    - Atomic commit shipped:
      `test(sync): stockseparate JOIN coverage cases A-E (260609-rie)`
  </done>
</task>

<!-- ──────────────────────────────────────────────────────────────────────── -->
<!-- TASK 6 — Verification (full-suite + smoke probe)                         -->
<!-- ──────────────────────────────────────────────────────────────────────── -->
<task type="auto">
  <name>Task 6: Full-suite verify + smoke probe (no commit)</name>
  <files>
    None — verification only.
  </files>
  <action>
    1. Focused test bundle GREEN:
       - tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php (5)
       - tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php (5)
       - tests/Architecture/StockSeparateJoinTest.php (3)
       → 13 NEW cases all green.

    2. Regression bundle GREEN:
       - tests/Feature/Sync/SupplierDbSyncCommandTest.php (helper-method coverage)
       - tests/Feature/Console/BackfillMerchantFeedCommandTest.php
       - tests/Feature/Console/AuditStockDivergenceCommandTest.php (260609-nku)
       - tests/Feature/Sync/SupplierFreshnessResolverTest.php (260608-g8x)
       - tests/Feature/Sync/AdCandidateScannerTest.php (if present)
       - tests/Feature/Sync/CompetitorPositionScannerTest.php (if present)
       - tests/Architecture/  (full architecture slice)

    3. Full Pest suite:
         vendor/bin/pest
       Baseline from 260609-nku: 1,965 pass / 222 pending / 3 known-fails.
       Expected delta: +13 pass / 0 new fails. Final: ≈1,978 / 222 / 3.

    4. CLI surface still resolves (no command-registration breakage):
         php artisan list | grep -E "supplier:db-sync|supplier:explain-cost"
       Expect both commands listed.

    5. SQL smoke probe (optional but high-value if dev DB has a synthetic
       fixture available — otherwise SKIP and document):
       - Connect to a non-prod copy of supplier_db.
       - Run the new trait-produced SELECT manually for SKU CP15851.
       - Expect `stock` column = 5659 (matches stockseparate.stock).
       - Run same query swapping in a known WestCoast SKU.
       - Expect `stock` column = the WestCoast feeds_products.stock for it.
       (If no dev supplier_db copy → skip + document in SUMMARY that this
       smoke runs at first post-deploy supplier:db-sync invocation.)

    NO commit on this task.
  </action>
  <verify>
    <automated>
      vendor/bin/pest tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php
      vendor/bin/pest tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php
      vendor/bin/pest tests/Architecture/StockSeparateJoinTest.php
      vendor/bin/pest tests/Feature/Sync/SupplierDbSyncCommandTest.php
      vendor/bin/pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php
      vendor/bin/pest tests/Feature/Console/AuditStockDivergenceCommandTest.php
      vendor/bin/pest tests/Architecture/
      vendor/bin/pest
      php artisan list | grep -E "supplier:db-sync|supplier:explain-cost"
    </automated>
  </verify>
  <done>
    - Full suite: +13 pass / 0 new fails vs 260609-nku baseline
    - php artisan list resolves both commands
    - SUMMARY.md drafted, including:
        * The 6-row scope-decision audit table from Task 1
        * Live numbers from the prod probe (125,319 / 192,011 / 5,659)
        * Operator post-deploy workflow: re-run `supplier:db-sync` first to
          rewrite products.buy_price + stock_quantity, THEN re-run
          `products:audit-stock-divergence` (260609-nku) so its findings
          table reflects the corrected MS-side stock view.
        * The deviation note: BackfillMerchantFeedCommand's 4 SQL sites do
          NOT read .stock — its buy-price decisions are fixed transitively
          via the products table once SupplierDbSyncCommand is fixed.
        * Pointer to the StockSeparateJoinTest regression guard.
  </done>
</task>

</tasks>

---

<verification>

## Phase-level acceptance

- [x] All 13 new tests GREEN; full suite +13 pass / 0 new fails vs baseline
- [x] StockSeparateJoinTest catches any future `FROM feeds_products` query
      that reads `.stock` without the trait — meta-assertion proves the
      regex still has teeth
- [x] WestCoast (is_stock_separate=0) byte-identical pre/post fix
- [x] Ingram catalogue: synthetic CP15851 case shows stock=5659 not 0
- [x] No new env() calls (EnvUsageTest stays green)
- [x] 4 commits shipped (T2, T3, T4, T5) — T1 audit + T6 verify are non-commit
- [x] No prod data written (sync is read-side only — no schema, no migrations)

## Live-impact sanity check (post-deploy, manual)

- After deploying this fix to prod, run `php artisan supplier:db-sync --dry-run`
  and confirm the report shows ~125k Ingram SKUs flipping from "no-stock" to
  "has-stock". If the number is suspiciously low (e.g. <50k), the JOIN may
  be misconfigured — STOP, do not run live, investigate.
- After the dry-run looks sane, re-run live `supplier:db-sync` (no flag).
- Then re-run `products:audit-stock-divergence`. The previous 256 phantom
  count from 260609-nku should drop substantially as the genuine Ingram
  drop-ship SKUs (which were misclassified as "phantom" because MS thought
  they were OOS) reconcile correctly.

</verification>

<success_criteria>

- Phase 7 cutover assumption corrected: the dual-file architecture is the
  real bug; the legacy Stock Updater plugin was probably also broken in
  the same way for the same reason — not a MS-side regression.
- 124k Ingram SKUs now visible to MS pricing/ad/sync decisions.
- Architecture test prevents silent reintroduction across all of
  app/Domain/Sync/, app/Console/Commands/, app/Domain/Pricing/Services/.
- Trait centralises the SQL fragments — when the supplier_db schema
  evolves (e.g. a `due_date` column gets added for inbound stock),
  ONE file changes, not 3.
- Stock-divergence audit (260609-nku) becomes trustworthy once
  supplier:db-sync is re-run post-deploy.
- buy_price decisions in BackfillMerchantFeedCommand fix transitively
  (downstream of products.buy_price) without touching that file.

</success_criteria>

<output>
On completion, write
  .planning/quick/260609-rie-join-supplier-db-stockseparate-when-feed/260609-rie-SUMMARY.md
with the standard quick-task SUMMARY structure, including:
- The 6-row scope-decision audit table (3 fixed, 7 out-of-scope with justification)
- Live prod numbers (125,319 / 192,011 / 5,659 for CP15851)
- 4 commit SHAs
- Suite delta (1,978 / 222 / 3 expected)
- Operator post-deploy workflow (sync first, then re-audit)
- Pointer to the regression-guard arch test
- The BackfillMerchantFeedCommand deviation note (brief assumption corrected)
</output>
