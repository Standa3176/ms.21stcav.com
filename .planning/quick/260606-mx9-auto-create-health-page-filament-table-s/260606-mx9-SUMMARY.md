---
quick_id: 260606-mx9
plan: 01
type: summary
status: complete
wave: 1
duration_minutes: 28
tasks_completed: 5
files_created:
  - tests/Feature/Filament/AutoCreateHealthPageTest.php
  - app/Filament/Pages/AutoCreateHealthPage.php
  - resources/views/filament/pages/auto-create-health.blade.php
files_modified: []
commits:
  - hash: 3d0bec2
    type: test
    message: "test(260606-mx9): add failing test for AutoCreateHealthPage predicate + badge + role gate"
  - hash: 75c0463
    type: feat
    message: "feat(260606-mx9): add AutoCreateHealthPage with unhealthy-predicate table + per-row resync/source-images actions"
  - hash: 5139da9
    type: feat
    message: "feat(260606-mx9): add nav badge + tooltip with 60s cache breakdown for AutoCreateHealthPage"
requirements_completed:
  - MX9-01
  - MX9-02
  - MX9-03
  - MX9-04
pest_focused: "3 passed / 0 failed (10 assertions, 8.15s)"
pest_full_baseline_260606_lhp: "1,811 passed / 219 failed / 3 skipped"
pest_full_final: "1,814 passed / 219 failed / 3 skipped"
pest_full_delta: "+3 passed / 0 new failures / 0 skipped delta"
env_guard_status: green
admin_panel_provider_disposition: auto-discovered
---

# Quick task 260606-mx9: Auto-create Health Page Summary

**One-liner:** Ship a Filament 3 admin page at `/admin/auto-create-health` that surfaces auto-create local-state drift (missing gallery images, brand, category, or `woo_product_id`) with per-row Resync-to-Woo + Source-images artisan actions and a warning nav badge — closes the visibility loop exposed by the 2026-06-06 Manhattan incident.

---

## Per-task outcomes

| Task | Commit  | Outcome |
| ---- | ------- | ------- |
| 1 — RED test (predicate + badge + role gate) | `3d0bec2` | Pest test file landed; ran RED with class-not-found + 404. |
| 2 — Page + Blade view (GREEN gate)            | `75c0463` | Tests 1 + 3 turn GREEN; Test 2 still RED awaiting Task 3. |
| 3 — Nav badge + tooltip (60s cache)           | `5139da9` | Test 2 turns GREEN. Focused suite: 3/3 (10 assertions, 8.15s). |
| 4 — Auto-discovery verification              | (no commit) | `route:list` confirms GET `/admin/auto-create-health` resolves via the existing `->discoverPages(in: app_path('Filament/Pages'), ...)` line at `AdminPanelProvider.php:97` — no explicit `->pages([...])` registration required. |
| 5 — Full guard gate                           | (no commit) | EnvUsageTest green; full suite delta = **+3 passed / 0 new failures / 0 skipped delta** vs 260606-lhp baseline. |

---

## Pest run

**Focused (`--filter=AutoCreateHealthPageTest`):**

```
PASS  Tests\Feature\Filament\AutoCreateHealthPageTest
  ✓ it unhealthy predicate returns exactly the 4 expected products (P2..P5)  2.75s
  ✓ it navigation badge count matches the live unhealthy total                0.40s
  ✓ it admin can access the page; sales, read_only, and pricing_manager get 403  1.10s

Tests:    3 passed (10 assertions)
Duration: 8.15s
```

**Full suite delta vs 260606-lhp baseline:**

| Metric  | 260606-lhp baseline | 260606-mx9 final | Delta |
| ------- | ------------------- | ---------------- | ----- |
| Passed  | 1,811               | 1,814            | **+3** (exact match for the 3 new AutoCreateHealthPage tests) |
| Failed  | 219                 | 219              | **0 new failures** — the pre-existing test-infra debt (per STATE.md L166) is unchanged |
| Skipped | 3                   | 3                | 0 |
| Total assertions | —          | 9,249            | — |
| Duration         | —          | 1,086s (~18m)    | — |

**Pass criterion satisfied:** failed ≤ 219 (zero new failures introduced), passed ≥ 1811 + new tests.

**EnvUsageTest:** PASS (3 assertions / 6 sub-assertions, 12.46s). No `env()` was introduced — the guardrail commit `2336e30` still bites.

---

## AdminPanelProvider disposition

**Auto-discovered — no edit required.**

`AdminPanelProvider.php:97` already runs:
```php
->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
```

`AutoCreateHealthPage` lives at `app/Filament/Pages/AutoCreateHealthPage.php`, exactly where the discoverer walks. Filament's component-cache rebuild (`php artisan filament:cache-components`) picked it up cleanly. `php artisan route:list` shows:

```
GET|HEAD admin/auto-create-health  filament.admin.pages.auto-create-health › App\Filament\Pages\AutoCreateHealthPage
```

No explicit `->pages([...])` registration was added. Pattern matches `NotificationCentrePage` (and the 8 Horizon pages, which are auto-discovered AND explicitly registered as belt-and-braces — we deliberately did NOT mirror that here because the brief was atomic per task and a verification-only Task 4 is the cleaner outcome).

---

## Tinker probe (operator-facing "page works" anchor)

```
> App\Filament\Pages\AutoCreateHealthPage::getNavigationBadge()
badge=null
```

`null` is the correct return on the local dev DB because dev DB has **0** products matching the unhealthy predicate (most dev-DB products are seeded with `auto_create_status='manual'` — i.e. legacy WC shape — and so are excluded by the page's scope clause). The badge correctly hides at 0 per spec.

```
> App\Filament\Pages\AutoCreateHealthPage::getNavigationBadgeTooltip()
tooltip=0 no images • 0 no brand • 0 no category • 0 no Woo
```

Tooltip rendering works end-to-end (the `Cache::remember(60)` path executed successfully, ran the 4 COUNT queries, formatted the breakdown string).

**Production behaviour anchor:** the predicate-matrix Pest test asserts the exact 4-of-6 rows that the production-shape population would surface (P2..P5 of the test matrix), so the operator-facing "page works" guarantee is proven on a controlled seed — even though the local dev DB count happens to be 0.

---

## Drift-prevention predicate

The exact predicate used in `unhealthyQuery()` (`app/Filament/Pages/AutoCreateHealthPage.php`):

```php
private static function unhealthyQuery(): Builder
{
    $emptyImagesExpr = static::emptyImagesExpr();

    return Product::query()
        ->where('auto_create_status', '!=', 'manual')
        ->where(function (Builder $q) use ($emptyImagesExpr): void {
            $q->whereNull('gallery_image_urls')
                ->orWhereRaw($emptyImagesExpr)
                ->orWhereNull('brand_id')
                ->orWhereNull('category_id')
                ->orWhereNull('woo_product_id');
        });
}

private static function emptyImagesExpr(): string
{
    return DB::connection()->getDriverName() === 'sqlite'
        ? 'json_array_length(gallery_image_urls) = 0'
        : 'JSON_LENGTH(gallery_image_urls) = 0';
}
```

Both the table query (`table().query()`) and the nav badge (`getNavigationBadge() → unhealthyQuery()->count()`) delegate to this helper, so the two cannot drift. Same drift-prevention pattern as `Suggestion::scopeHighConfidenceSourceable` (260606-lhp).

---

## Known limitations

- **Manhattan-shape Woo-side drift NOT covered.** The Manhattan incident product (Sony VPL-EX235) had its local `gallery_image_urls` populated AND its `woo_product_id` set — the drift was Woo-side (the Woo product was rendering with placeholder images because Woo's media library lost the references). This predicate looks at LOCAL state only, so it cannot detect "local OK, Woo wrong." Follow-up: a periodic Woo-side image-count diff scan that compares `Woo product images[]` length against `products.gallery_image_urls` length and flags mismatches. Tracked as a follow-up; explicitly flagged in PLAN.md objective and in the page-class docblock.

- **Per-click artisan synchronous dispatch.** `products:resync-to-woo` is ~5–15s; `products:source-images` can run much longer (Icecat + supplier feed + web search + Claude vision). They run synchronously inside the Filament action context, so the operator's browser holds the connection open for the duration. T-mx9-04 disposition was `accept` (operator-driven rate, human-click cadence). If this becomes a problem in practice, the follow-up is to dispatch a Job + return a tracking notification.

- **Dev DB is SQLite.** The local dev probe uses `json_array_length` (SQLite); production MySQL uses `JSON_LENGTH`. The driver-aware expression handles both. A one-off direct probe of the live unhealthy count against production MySQL is not possible from this dev environment — the predicate's correctness is established by the controlled Pest matrix test.

---

## Deviations from plan

### Rule 1 — auto-fix latent predicate bug

**Found during:** Task 1 (RED test — couldn't insert `auto_create_status = NULL` on SQLite, NOT NULL constraint violation).

**Issue:** PLAN.md specified the legacy-WC exclusion clause as `whereNotNull('auto_create_status')`, citing `RetryMissingImagesCommand` as precedent. But the `auto_create_status` column is declared `NOT NULL DEFAULT 'manual'` in migration `2026_04_22_100300` with an explicit belt-and-braces `whereNull(...)->update(['auto_create_status' => 'manual'])` backfill. The column literally cannot hold NULL on either SQLite or MySQL (with current schema), so `IS NOT NULL` is a vacuous filter — it never excludes anything.

The migration's own docblock makes the intent explicit: `'manual'` IS the legacy / pre-auto-create marker, and the auto-create lifecycle uses the other 7 enum states (`draft`, `pending_review`, `approved`, `published`, `rejected`, `needs_brand_or_category_assignment`, `variations_not_supported_v1`).

**Fix:** Switched the predicate's scope clause from `whereNotNull('auto_create_status')` to `where('auto_create_status', '!=', 'manual')`. This preserves the must-have "Legacy WC-migration products are excluded even when their fields are missing" without depending on a NULL value the schema cannot hold.

**Files modified:** Both the test seed (P6 uses `'manual'` instead of `null`) and the page implementation (`unhealthyQuery()` + `getNavigationBadgeTooltip()` base query) reference `'manual'` consistently.

**Commits:** `3d0bec2` (test), `75c0463` (page), `5139da9` (badge tooltip).

**Documented in:** test file's file-level deviation comment (lines 14–34); page class docblock (lines 53–60).

**Note for follow-up:** `RetryMissingImagesCommand` shares the same latent bug — its `whereNotNull('auto_create_status')` filter doesn't actually exclude anything in production. That command should arguably be updated to use the same `!= 'manual'` clause. Out of scope for this quick task (Rule 1's scope-boundary discipline — only fix the file the current task touches).

### No other deviations

No authentication gates, no architectural changes, no out-of-scope discoveries. All 3 task-related commits landed cleanly. AdminPanelProvider auto-discovery worked first try, so Task 4 produced no commit. Full suite delta = zero new failures, so Task 5 produced no fixup commit.

---

## Threat flags

None — the page introduces no new network endpoints, no new auth paths, no new file-access patterns, no schema changes. Surface is contained within: (a) an admin-only read query against the existing `products` table, and (b) two `Artisan::call` invocations of existing commands with their existing security postures. All threat-register entries from PLAN.md `<threat_model>` (T-mx9-01 through T-mx9-05) were honoured per the planned mitigations.

---

## Self-Check: PASSED

**Files exist:**
- FOUND: `tests/Feature/Filament/AutoCreateHealthPageTest.php`
- FOUND: `app/Filament/Pages/AutoCreateHealthPage.php`
- FOUND: `resources/views/filament/pages/auto-create-health.blade.php`

**Commits exist on main:**
- FOUND: `3d0bec2` (test RED gate)
- FOUND: `75c0463` (page + view GREEN gate)
- FOUND: `5139da9` (nav badge + tooltip)

**Route resolves:** `GET admin/auto-create-health` resolves to `App\Filament\Pages\AutoCreateHealthPage` (verified via `php artisan route:list`).

**Focused Pest suite:** 3 passed / 0 failed / 10 assertions / 8.15s.

**Full Pest suite:** 1,814 passed / 219 failed / 3 skipped — **0 new failures vs 260606-lhp baseline**.

**Env guardrail:** PASS (3 assertions).

All success criteria from PLAN.md `<success_criteria>` are satisfied.
