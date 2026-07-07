---
phase: 260707-gsy-make-suggestions-obvious-tier-1-add-a-re
plan: 01
subsystem: Suggestions
tags: [filament, ux, suggestions, auto-create, readiness]
requires:
  - supplier_sku_cache table (supplier:refresh-sku-cache)
  - config('product_auto_create.brands_to_add_exclude')
provides:
  - SuggestionResource::readinessFrom(bool,?string)
  - SuggestionResource::readiness(Suggestion)
  - Readiness badge column on /admin/suggestions
affects:
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
tech-stack:
  added: []
  patterns:
    - "Engine-independent sourceable check: PHP-extract SKU + where()->exists() (no JSON-in-SQL)"
    - "Per-request static memo for per-row Filament column lookups"
key-files:
  created:
    - tests/Unit/Suggestions/SuggestionReadinessTest.php
    - tests/Feature/Suggestions/SuggestionReadinessColumnTest.php
  modified:
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
    - tests/Feature/Suggestions/RejectWithAgentFeedbackActionTest.php
    - tests/Feature/Competitor/MarginChangeSuggestionApproveActionTest.php
    - tests/Feature/CRM/SuggestionReplayActionTest.php
    - tests/Feature/SuggestionInboxTest.php
    - tests/Feature/SuggestionResourceQueryCountTest.php
decisions:
  - "sourceable computed engine-independently (PHP-extracted SKU + supplier_sku_cache exists()) — no JSON_UNQUOTE/JSON_EXTRACT SQL, dodging the SQLite/MariaDB trap that repeatedly bit this page"
  - "default filters via ->default() only; option lists + getEloquentQuery guardrail-hiding untouched"
  - "existing Livewire table-action tests clear the new default filters so non-NPO/non-pending records stay visible (assertions unchanged)"
metrics:
  duration: ~45m
  completed: 2026-07-07
  tasks: 1
  commits: 2
---

# Phase 260707-gsy Plan 01: Make Suggestions Obvious (Tier 1) Summary

Readiness badge + default-to-actionable filters + post-Auto-create feedback on the
`/admin/suggestions` list — so operators see what-will-create up front, land on the
actionable set, and get visible feedback instead of a silent list.

## What Shipped

### Readiness verdict (pure + memoised)
- `readinessFrom(bool $sourceable, ?string $brand): array{label,color}` — PURE:
  - not sourceable → `Not sourceable` / gray
  - sourceable + blank/junk brand → `Needs brand` / warning
  - sourceable + usable brand → `Ready` / success
  - junk = `config('product_auto_create.brands_to_add_exclude')` (`specials`, `un-branded`, `unbranded`), case-insensitive
- `readiness(Suggestion): ?array` — null for non `new_product_opportunity` kinds; memoised per request in `static $readinessMemo`.
- **Engine-independent sourceable check (the load-bearing decision):** SKU is pulled from `evidence.sku` in PHP, lowercased + trimmed, then `DB::table('supplier_sku_cache')->where('sku', $sku)->exists()`. No `JSON_UNQUOTE`/`JSON_EXTRACT` SQL — this is exactly the SQLite↔MariaDB divergence that has repeatedly broken this page. Cost = 1 indexed PK lookup per NPO row (memoised).

### Readiness column
- `TextColumn::make('readiness')` badge placed right after Status: Ready (success) / Needs brand (warning) / Not sourceable (gray), `'—'` for other kinds, with per-verdict tooltips explaining each state.

### Default-to-actionable filters
- Kind SelectFilter `->default('new_product_opportunity')`, Status SelectFilter `->default('pending')`. Option lists and the `getEloquentQuery` guardrail-hiding of `agent_guardrail_blocked` are unchanged — defaults are just the initial selection.

### Post-Auto-create feedback
- Table `->poll('30s')` so rows flip to *applied* live as the Horizon pipeline finishes.
- `auto_create_full` bulk-action success-notification **body** rewritten to set expectations + point onward ("these rows will change to applied here… this list refreshes itself… appear under Auto-create Health… result lands in your notifications bell"). Title `"{n} SKU(s) queued"` and the `RunAutoCreatePipelineJob::dispatch(...)` call are unchanged.

## Tests

- `tests/Unit/Suggestions/SuggestionReadinessTest.php` — 5 pure `readinessFrom` cases (Barco→Ready, ''→Needs brand, Specials/Un-Branded junk→Needs brand, not-sourceable→Not sourceable).
- `tests/Feature/Suggestions/SuggestionReadinessColumnTest.php` — 4 DB cases: seed `supplier_sku_cache` + pending NPO → `readiness()==Ready`; SKU absent → Not sourceable; brand '' → Needs brand; `margin_change` kind → null. Memo reset between assertions via reflection.

TDD: RED confirmed (9 failed, helpers absent) → GREEN (9 passed).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Default filters hid records from existing Livewire table-action tests**
- **Found during:** regression run of `tests/Feature/Suggestions` + related resource tests.
- **Issue:** Adding `->default('new_product_opportunity')` / `->default('pending')` made the Livewire table mount pre-filtered, so 10 pre-existing table-action tests that create `margin_change` / `crm_push_failed` / `test` / non-pending records could no longer find those records in the table (`callTableAction`/`assertCanSeeTableRecords` failed). This is the known "Filament action-visibility drift" pattern noted in STATE.md test-suite remediation.
- **Fix:** Cleared the two new default filters (`->set('tableFilters.kind.value', null)` + `->set('tableFilters.status.value', null)`) in each affected Livewire chain so the tests exercise the full record set as before. Assertions unchanged.
- **Files modified:** RejectWithAgentFeedbackActionTest, MarginChangeSuggestionApproveActionTest, CRM/SuggestionReplayActionTest, SuggestionInboxTest, SuggestionResourceQueryCountTest.
- **Commit:** d3133c8

## Deferred / Out of Scope

5 pre-existing failures (4 `SuggestionResourceAutoCreateKindsTest` `correlation_id NOT NULL`, 1 `NewProductOpportunityApproveActionTest` `BindingResolutionException`) were verified failing on the committed baseline BEFORE this change and are unrelated to the display work. Logged in `deferred-items.md`; part of the known test-infra debt.

## Verification

- readiness unit + feature: **9 passed**
- `pest tests/Feature/Suggestions tests/Unit/Console/BrandsToAddIndexTest.php`: **37 passed**
- `pint --test` (resource + new tests): `{"result":"pass"}`
- Confirmed: a sourceable + branded pending NPO reads **Ready**; a not-in-cache SKU reads **Not sourceable**.

## Operator Notes

- **Deploy:** push main → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Suggestions now opens on **Pending · New products** with a **Readiness** column: **Ready** = will create (bulk Auto-create is safe here), **Needs brand** = in the feed but no usable brand (parks), **Not sourceable** = no supplier carries it. After Auto-create the list auto-refreshes so rows flip to *applied*, and the toast points to Auto-create Health + the bell.

## Tier-2 Follow-ups (not in this task)

- A Readiness FILTER (show only Ready) replacing the confusing Brand-on-Woo ternary.
- Trimming/grouping the 7-filter row.
- Splitting the failure kinds (crm/auto-create/guardrail) into their own view.
- Renaming 'Comp' + column tooltips + a page description.

## Self-Check: PASSED
