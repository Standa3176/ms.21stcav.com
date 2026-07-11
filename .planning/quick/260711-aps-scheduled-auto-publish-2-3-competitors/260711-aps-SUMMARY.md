# 260711-aps — Twice-weekly auto-PUBLISH of 2-or-3-competitor SKUs (+ audit log) — SUMMARY

**One-liner:** A twice-weekly (Mon+Thu 05:00 London) scheduled run that auto-PUBLISHES
straight to live Woo the pending, sourceable SKU suggestions with exactly 2 or 3
competitors, recording one `auto_publish_log` row per real publish (with the 2-vs-3
split) — built on the existing `products:draft-from-suggestions` command, unchanged
except for an additive competitor-band filter + a confirmation-gated audit write.

## ⚠ Production activation note (READ THIS)

**Live publishing requires `WOO_WRITE_ENABLED=true` in prod.** Until that flag is
flipped, `PublishProductJob` records a `SyncDiff` instead of touching Woo and does NOT
mark rows published — so the scheduled run is a **safe shadow no-op**: no live Woo
write, no false `published` status, and **NO `auto_publish_log` row**. This shadow no-op
is explicitly tested. Batch size is tunable without a deploy via
`AUTO_PUBLISH_SCHEDULED_LIMIT` (config `product_auto_create.scheduled_publish_limit`,
default 25).

## Commits (atomic, per task)

| Task | SHA | Summary |
|------|-----|---------|
| 1 | `3a256db` | `--min-competitors`/`--max-competitors` inclusive band on the Suggestion-walk path (driver-portable) |
| 2 | `fd386b9` | `auto_publish_log` migration + `AutoPublishLogEntry` model + factory + confirmation-gated `recordAutoPublish()` write |
| 3 | `40fedf6` | Read-only "Auto-Publish Log" Filament viewer under the **Woo Maintenance** nav group |
| 4 | `035fb9b` | Twice-weekly schedule (Mon+Thu 05:00 London) + tunable `scheduled_publish_limit` config |

## What each task delivered

### Task 1 — competitor-count band filter
- Added `--min-competitors=` / `--max-competitors=` (default null = no filter, fully
  backward compatible). Applies **only** to the Suggestion-walk path, not the explicit
  `--skus` path.
- Filters `evidence.supporting_competitors` inclusively via the new public seam
  `pendingOpportunitySuggestionsQuery(?int $min, ?int $max)`.
- **Driver-portable** (memory: sqlite-mariadb-strict-trap): SQLite
  `CAST(json_extract(evidence,'$.supporting_competitors') AS INTEGER)`; MariaDB
  `CAST(JSON_UNQUOTE(JSON_EXTRACT(...)) AS SIGNED)` — **no MySQL-only `CAST AS UNSIGNED`**
  in a shared path.

### Task 2 — auto-publish audit log
- `auto_publish_log` table: `id, sku, product_id, woo_product_id, competitor_count,
  supplier_count(null), source(default 'scheduled_auto_publish'), batch_correlation_id,
  published_at`; indexes on `published_at` + `competitor_count`. `published_at` is the
  authoritative timestamp (no created_at/updated_at — append-only convention).
- `App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry` (final, fillable, casts) +
  factory with a `competitors(int)` state.
- `recordAutoPublish()` writes **ONE** row per **confirmed** real publish — it re-reads
  `auto_create_status === 'published'` **AND** `woo_product_id` present before writing;
  otherwise returns false and writes nothing. `competitor_count` comes from the driving
  suggestion (captured during the walk). `supplier_count` is nullable forward-compat
  (the current walk records null — documented, not a stub blocking the goal).
- Wired into the `--auto-approve` loop: the shadow-mode branch now explicitly writes no
  audit row and leaves the product in the review inbox.

### Task 3 — read-only viewer
- `AutoPublishLogResource` under nav group **'Woo Maintenance'**, admin-only
  (`canViewAny`/`canAccess` gate mirroring `WooMaintenanceOverviewPage`), producer-owned
  (`canCreate=false`, index page only — no edit/delete).
- Columns: `published_at` / `sku` / `competitor_count` (badge) / `woo_product_id`
  (links to the live Woo product via wp-admin edit) / `source`. Default sort
  `published_at desc`. Filters: `competitor_count` (2/3) + `published_at` date range.
- Auto-discovers via the existing ProductAutoCreate Filament discovery path.

### Task 4 — schedule + safeguards
- `routes/console.php`: `products:draft-from-suggestions` with `--min-competitors=2
  --max-competitors=3 --auto-approve --create-missing-brands --source-images --limit=<cfg>
  --no-confirm`, Mon+Thu 05:00 London, `withoutOverlapping()` + `onOneServer()`.
- `config('product_auto_create.scheduled_publish_limit')` (env
  `AUTO_PUBLISH_SCHEDULED_LIMIT`, default 25).

## The three required guarantees — each explicitly tested

1. **Competitor 2/3-only selection** — `DraftFromSuggestionsCompetitorFilterTest`: seeds
   suggestions with `supporting_competitors` 1/2/3/4; `pendingOpportunitySuggestionsQuery(2,3)`
   returns ONLY the 2 and 3; 1 and 4 excluded; null/null = no filter (backward compatible);
   only pending `new_product_opportunity` rows walked. Re-asserted under the scheduled
   arg-set in `ScheduledAutoPublishTest`.
2. **Shadow-mode no-op writes NO audit row** — `DraftFromSuggestionsAuditLogTest`: a
   product left un-published with no `woo_product_id` (the shadow-mode outcome) →
   `recordAutoPublish()` returns false and `auto_publish_log` stays empty; plus two
   defensive cases (woo id but not published; published but no woo id) → no row.
3. **Audit-log competitor_count split** — `DraftFromSuggestionsAuditLogTest`: a confirmed
   2-competitor and a confirmed 3-competitor publish produce two rows with
   `competitor_count` 2 and 3 respectively; single confirmed publish writes exactly one
   row with correct sku / woo_product_id / published_at / batch_correlation_id.

Tests never hit real Woo — they exercise the extracted seams (query builder +
`recordAutoPublish`) and assert on local state + the audit log. (The command's supplier
walk uses a live mysqli connection that cannot be faked in-process, so the full command
is intentionally not invoked in tests — same strategy as the existing
`DraftFromSuggestionsCreateBrandTest`.)

## Verify results

- `pest` (new): 19 passed — competitor filter (5) + audit write incl. shadow no-op (5) +
  viewer (5) + schedule selection (4).
- `pest` regression: **ProductAutoCreate suite 217 passed** (757 assertions);
  **Suggestions suite 43 passed** (188 assertions); **DraftFromSuggestions console suite
  38 passed**. No regressions.
- `php artisan route:list --path=admin` → **exit 0**; `admin/auto-publish-log` resolves.
- `php artisan schedule:list` → shows the entry
  `0 4 * * 1,4  php artisan products:draft-from-suggestions --min-competitors=2
  --max-competitors=3 --auto-approve ...` (04:00 UTC = 05:00 London BST — timezone applied).
- `pint` → pass on all changed files.
- `vendor/bin/deptrac analyse` → **0 violations, 0 warnings** (model in ProductAutoCreate;
  resource in the presentation/Http layer).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `->twiceWeekly(1,4,'05:00')` does not exist in Laravel's scheduler**
- **Found during:** Task 4 (RED — `BadMethodCallException: Method
  Illuminate\Console\Scheduling\Event::twiceWeekly does not exist`).
- **Issue:** Laravel's scheduler provides `twiceDaily`/`twiceMonthly` but no
  `twiceWeekly` helper; the plan's literal snippet would fatal at boot.
- **Fix:** Expressed the identical Mon+Thu 05:00 cadence as `->cron('0 5 * * 1,4')`
  (matches this file's existing `->cron('... 1-5')` convention). The registered event's
  expression is verified as `0 5 * * 1,4` in `ScheduledAutoPublishTest`.
- **Files modified:** `routes/console.php`.
- **Commit:** `035fb9b`.

## Out-of-scope items (logged, not fixed)

- Pre-existing working-tree noise left untouched per the plan: `storage/app/research/
  supplier-probe.json` (deletion), `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`
  (modified), untracked `.claude/`.
- Pre-existing test-suite issue: a global function name collision (`seedGaRow()`
  redeclared across `tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php` and
  `tests/Feature/Integrations/MarketingOverviewStatsTest.php`) fatals a whole-suite run.
  Unrelated to this task; regression verification was run by directory to avoid it.
- Pre-existing `pint` findings in untouched ProductAutoCreate files (IcecatClient,
  ProductImageProcessor, AutoCreateReviewResource, etc.) — not modified here.

## Self-Check: PASSED

- Files exist: command, model, migration, factory, resource, list page, config, routes,
  4 test files — all confirmed present (created/edited this session).
- Commits exist: `3a256db`, `fd386b9`, `40fedf6`, `035fb9b` — all in `git log`.
