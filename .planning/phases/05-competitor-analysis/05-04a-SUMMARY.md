---
phase: 05-competitor-analysis
plan: 04a
subsystem: competitor
tags: [filament-resources, rbac, shield-restoration, suggestion-approve, alert-recipient-toggle, comp-05, comp-09, p5-f, d-04, d-07, d-08]

requires:
  - phase: 01-foundation
    provides: "Filament Shield plugin (admin panel); RolePermissionSeeder LIKE-pattern idempotency; PolicyTemplateIntegrityTest (floor=21); SuggestionPolicy (admin-only); AlertRecipientResource (receives_* toggle pattern)"
  - phase: 02-supplier-sync
    provides: "SyncRunResource + ImportIssueResource shape (read-only list, edit-only update, TernaryFilter for resolved); Shield 3.9.10 :: vs underscore separator lesson"
  - phase: 04-bitrix24-crm-sync
    provides: "shield:generate restoration protocol (last executed Plan 04-04); SuggestionResource::replay kind-specific action pattern; CrmPushLogResource read-only-with-scope pattern"
  - plan: 05-01
    provides: "5 Competitor policies (hand-written, P5-F docblocks); 5 models + factories; 'Competitor Intelligence' navigation group claim"
  - plan: 05-02
    provides: "NewProductOpportunityApplier (stub body; evidence JSON with sku + supporting_competitors + competitor_sightings)"
  - plan: 05-03
    provides: "MarginChangeApplier (real producer); D-07 evidence JSON shape FROZEN (sku + competitor_name + our_current_margin_bps + proposed_margin_bps + margin_delta_bps)"

provides:
  - "3 Filament Resources under app/Domain/Competitor/Filament/Resources/ with 'Competitor Intelligence' navigation group (sort 10/20/30)"
  - "CompetitorPriceResource — read-only price history browser; SKU search + competitor filter + recorded_at date range; canCreate/canEdit/canDelete all false (COMP-07 immutable history)"
  - "CompetitorIngestRunResource — read-only run log mirroring SyncRunResource shape; status BadgeColumn (started/completed/failed); parse_errors_count badge column (danger when > 0); filter by status + competitor"
  - "CsvParseErrorResource — edit-only form with resolved_at DateTimePicker (only writable field); all other fields disabled+dehydrated; issue_type multi-select filter + TernaryFilter resolved/unresolved"
  - "AdminPanelProvider: ->discoverResources(app_path('Domain/Competitor/Filament/Resources'), ...) registered"
  - "RolePermissionSeeder: explicit Phase 5 whitelist (NOT LIKE patterns — MySQL single-char `_` wildcard bug on %_csv_parse_error was catching create/delete/force_delete); pricing_manager gets view+update on csv_parse_error (D-04) + competitor_csv_mapping (D-04 forward-compat); sales gets view_any/view on competitor_price + competitor_ingest_run ONLY; read_only gets 0 Phase-5 perms"
  - "DatabaseSeeder: belt-and-braces UPDATE for ops@meetingstore.co.uk fallback row → receives_competitor_alerts=true on every seed run (handles pre-Phase-5 rows)"
  - "SuggestionResource: 2 new kind-specific actions — approve_margin_change (D-07 evidence rendering with old→new margin delta), approve_new_product_opportunity (supporting_competitors count rendering)"
  - "SuggestionResource: new supporting_competitors TextColumn (badge, state()-based; renders int for new_product_opportunity kind, null otherwise)"
  - "SuggestionResource: generic approve action visibility narrowed to exclude the 3 kind-specific kinds (margin_change / new_product_opportunity / crm_push_failed)"
  - "AlertRecipientResource: Toggle::make('receives_competitor_alerts') appended after receives_crm_alerts in form; IconColumn added to table"
  - "8 new Pest feature tests (CompetitorResourcesAccessMatrixTest + ShieldRestorationProtocolTest + MarginChangeSuggestionApproveActionTest + NewProductOpportunityApproveActionTest + AlertRecipientCompetitorToggleTest)"
  - "P5-F restoration protocol executed: shield:generate overwrote 13 hand-written policies (RolePolicy + AlertRecipientPolicy + 5 Competitor + 2 Pricing + 2 Products + ImportIssuePolicy + SyncRunPolicy + SuggestionPolicy + 2 CRM) + created 1 stub (app/Foundation/Integration/Policies/IntegrationEventPolicy.php). All 13 restored via `git checkout HEAD --`; stub directory rm -rf'd; zero `{{ ` leaks remain."

affects:
  - "05-04b-filament-pages-stale-feed (CompetitorAnalysisPage + CsvIngestIssuesPage can consume the same SuggestionResource conventions; the 3 Resources ship the list/edit shapes that Page-level widgets can compose against)"
  - "05-05-retention-guardrails-verification (CsvParseErrorResource edit-only shape establishes the triage UI; retention prune commands can ignore csv_parse_errors rows — the ops-managed surface is now in place)"
  - "Phase 6 supplier-request-list integration (approve_new_product_opportunity Filament action is the admin-facing entry point; Phase 6 replaces only the NewProductOpportunityApplier body)"
  - "Phase 7 dashboard widgets (SuggestionResource evidence/payload rendering conventions established; supporting_competitors badge is the first kind-aware column pattern)"

tech-stack:
  added:
    - "None — 100% reuse. Filament 3 TableAction visibility + authorize + requiresConfirmation + modalDescription patterns; data_get() helper for JSON-column accessor; state() callback for kind-aware column rendering."
  patterns:
    - "Shield permission format for multi-word PascalCase: `{action}_{word1}::{word2}::{word3}` — CompetitorPrice → competitor::price; CompetitorIngestRun → competitor::ingest::run; CsvParseError → csv::parse::error. Single-word classes stay underscore: `view_any_role`, `view_product`. This matches Phase 2's `sync::run` / `import::issue` precedent — Shield 3.9.10 splits on PascalCase word boundaries with `::` separator."
    - "Explicit permission whitelist over LIKE patterns for actions-with-mixed-grants (like csv_parse_error where pricing_manager gets view+update but not create/delete). MySQL `_` is a single-char wildcard in LIKE, so `%_csv_parse_error` matches EVERY action — confirmed in dev during this plan when pricing_manager accidentally got create/delete/force_delete. Fix: `whereIn([...])` with explicit action prefixes for Phase 5 resources; reserve LIKE for all-or-nothing grants (admin wildcard, read_only view-only)."
    - "Kind-specific TableActions (per Plan 05-04a): each kind-specific action uses ->visible(fn ($r) => $r->kind === 'X' && $r->status === 'pending'); generic ->approve() narrowed with !in_array exclusion. Cleaner UX than showing generic Approve + kind-specific Approve simultaneously."
    - "state() callback TextColumn for JSON-column accessor (supporting_competitors): ->state(fn ($record) => $record->kind === 'X' ? (int) data_get(...) : null) + ->placeholder('—') — renders the integer for matching kinds, dash for others. No DB change + no accessor method needed; reads directly from evidence JSON."
    - "P5-F restoration protocol is now quad-executed (Phase 1 Plan 02 + Phase 2 Plan 04 + Phase 4 Plan 04 + Phase 5 Plan 04a) — the `git checkout HEAD -- <path>` + `rm -rf app/Foundation/Integration/Policies` ritual is stable. Every plan that adds Filament Resources + touches Shield should now bake this step in."

key-files:
  created:
    - "app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php"
    - "app/Domain/Competitor/Filament/Resources/CompetitorPriceResource/Pages/ListCompetitorPrices.php"
    - "app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php"
    - "app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource/Pages/ListCompetitorIngestRuns.php"
    - "app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php"
    - "app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/ListCsvParseErrors.php"
    - "app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/EditCsvParseError.php"
    - "tests/Feature/Competitor/CompetitorResourcesAccessMatrixTest.php"
    - "tests/Feature/Competitor/ShieldRestorationProtocolTest.php"
    - "tests/Feature/Competitor/MarginChangeSuggestionApproveActionTest.php"
    - "tests/Feature/Competitor/NewProductOpportunityApproveActionTest.php"
    - "tests/Feature/Competitor/AlertRecipientCompetitorToggleTest.php"
  modified:
    - "app/Providers/Filament/AdminPanelProvider.php — Competitor Resource discovery path added"
    - "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php — kind-specific approve actions + supporting_competitors column"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php — receives_competitor_alerts Toggle + IconColumn"
    - "database/seeders/RolePermissionSeeder.php — Phase 5 explicit whitelist (NOT LIKE patterns for csv_parse_error / competitor_csv_mapping)"
    - "database/seeders/DatabaseSeeder.php — belt-and-braces UPDATE for ops@meetingstore.co.uk.receives_competitor_alerts=true"

key-decisions:
  - "RolePermissionSeeder uses explicit whitelist (not LIKE) for Phase 5 resources with mixed grants. During dev the `%_csv_parse_error` LIKE pattern caught pricing_manager's role with create + delete + force_delete + restore + replicate + reorder permissions because MySQL `_` is a single-char wildcard matching any prefix character (view_csv_parse_error, update_csv_parse_error, delete_csv_parse_error all match). D-04 grants pricing_manager view + update ONLY — explicit whereIn enumeration is the fix. Reserve LIKE for all-or-nothing scopes (admin uses Permission::all(); read_only uses view_%)."
  - "Shield 3.9.10 emits `::` separator for multi-word PascalCase (competitor::price, competitor::ingest::run, csv::parse::error) and underscore for single-word (role). The seeder's whitelist covers BOTH forms for forward-compat in case Shield's separator convention changes — e.g. `view_competitor_price` (underscore) and `view_competitor::price` (observed :: form) both listed. Plan 05-02's CompetitorCsvMapping perms aren't generated because no CompetitorCsvMappingResource ships in 05-04a (per revision guidance); the forward-compat entries are insurance for any future plan that adds one."
  - "PolicyTemplateIntegrityTest floor stays at 21 (already bumped in Plan 05-01). Plan 05-04a does NOT ship new policies — the 3 new Filament Resources bind against existing CompetitorPricePolicy / CompetitorIngestRunPolicy / CsvParseErrorPolicy from Plan 05-01. Plan truth-list language 'bumped +5' describes the historical bump (16→21) that Plan 05-01 already committed."
  - "Generic Approve action narrowed to exclude 3 kinds (margin_change, new_product_opportunity, crm_push_failed) rather than keeping it as a catch-all. Alternative considered: leave generic Approve as a shared fallback + rely on visibility for the kind-specific actions. Rejected — a row for kind=margin_change would render BOTH approve buttons simultaneously, confusing the UX. Kind-specific wins; generic remains as a future-proof escape hatch for Suggestions with no registered applier yet."
  - "supporting_competitors column uses ->state() callback returning int|null (not an accessor on the Suggestion model). Accessor would have required a $appends entry on the model + extra columns in every table render; the state() closure runs once per row at render time and only for this Filament context. Zero DB churn; cleaner separation between domain model and UI rendering."
  - "CompetitorResource (CRUD of the competitors table) NOT shipped per revision guidance. D-01 filename-prefix auto-discovery (Plan 05-02 CompetitorWatchCommand.firstOrCreate) remains the primary competitor-row creation path. Admins needing to promote a status=pending row to status=active use `php artisan tinker` or a targeted follow-up plan. Covered-by-checker note added to plan frontmatter."
  - "P5-F restoration: IntegrationEventPolicy stub deletion is permanent. Phase 4 Plan 04 decided the hand-written CrmPushLogPolicy registered via explicit Gate::policy(IntegrationEvent, CrmPushLogPolicy) in AppServiceProvider is the single source of truth for IntegrationEvent authz. Every shield:generate run MUST be followed by `rm -rf app/Foundation/Integration/Policies` to maintain that invariant. ShieldRestorationProtocolTest now asserts this dir doesn't exist."

requirements-completed:
  - COMP-05
  - COMP-09

duration: ~35 min
completed: 2026-04-19
---

# Phase 05 Plan 04a: Filament Resources + RBAC Summary

**3 read-only Competitor Filament Resources (price/ingest-run/csv-parse-error) under 'Competitor Intelligence' navigation group + SuggestionResource kind-specific Approve actions for margin_change (D-07 evidence rendering) + new_product_opportunity (supporting_competitors count rendering) + AlertRecipientResource receives_competitor_alerts Toggle + explicit Phase 5 RolePermissionSeeder whitelist (NOT LIKE — MySQL `_` wildcard bug caught pricing_manager accidentally getting create/delete/force_delete on csv_parse_error) + P5-F shield:generate restoration protocol (13 policies restored from HEAD, 1 IntegrationEventPolicy stub removed, 0 `{{ ` placeholder leaks). Full role matrix verified: admin=163 perms, pricing_manager=57, sales=6, read_only=28. 11 new Pest tests green; full Competitor + Architecture suite 161/0; Suggestions+CRM+Alerting filtered 215/1-skipped/0-failed; Deptrac 0 violations.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-04-19T21:25Z
- **Completed:** 2026-04-19T22:00Z
- **Tasks:** 2 (non-TDD; autonomous)
- **Commits:** 2 (+ 1 final metadata commit pending)
- **Files created:** 12 (7 Resource files + 5 test files)
- **Files modified:** 5 (AdminPanelProvider + SuggestionResource + AlertRecipientResource + 2 seeders)

## Accomplishments

### 3 Filament Resources — read-only intent modelled correctly

| Resource | canCreate | canEdit | canDelete | Why |
|----------|-----------|---------|-----------|-----|
| CompetitorPriceResource | false | false | false | COMP-07: history never truncated, producer-owned |
| CompetitorIngestRunResource | false | false | false | Runs are IngestCompetitorCsvJob-owned; observed-only |
| CsvParseErrorResource | false | policy-gated | policy-gated | D-04: resolved_at is the ONLY writable field; admin + pricing_manager can edit, admin-only delete |

All 3 live under `Competitor Intelligence` navigation group at sort 10/20/30 so the sidebar order matches the "upstream → downstream" ingest flow visually (prices are the payload, runs are the container, parse errors are the triage).

### P5-F restoration protocol — 4th successful execution

| Step | Outcome |
|------|---------|
| shield:generate --all | Ran; created 163 permissions total (12 new Phase 5: 12 × competitor_price + 12 × competitor_ingest_run + 12 × csv_parse_error) |
| Policies damaged | 13 (RolePolicy + AlertRecipientPolicy + 5 Competitor + 2 Pricing + 2 Products + ImportIssuePolicy + SyncRunPolicy + SuggestionPolicy + 2 CRM) |
| IntegrationEventPolicy stub created | YES — at app/Foundation/Integration/Policies/IntegrationEventPolicy.php (Shield auto-discovered IntegrationEvent model) |
| Restoration | `git checkout HEAD --` on 13 policies; `rm -rf app/Foundation/Integration/Policies` on the stub dir |
| Post-restoration `{{ ` grep | 0 matches across all Policies paths |
| PolicyTemplateIntegrityTest | GREEN (floor=21 still asserts; gate-pairs still resolve to hand-written Domain policies) |

### Roles × Resources permission matrix FROZEN (for Plan 05-04b + Phase 7)

| Role            | CompetitorPrice     | CompetitorIngestRun | CsvParseError       | Suggestion       | AlertRecipient   |
|-----------------|---------------------|---------------------|---------------------|------------------|------------------|
| admin           | view (all actions)  | view (all actions)  | view + update + delete | view + approve/reject + approve_margin_change + approve_new_product_opportunity + replay | view + create + update (admin-only) |
| pricing_manager | view_any + view     | view_any + view     | view_any + view + **update (D-04)** | 403 (admin-only SuggestionPolicy) | 403 |
| sales           | view_any + view     | view_any + view     | 403                 | 403              | 403              |
| read_only       | 403                 | 403                 | 403                 | 403              | 403              |

**Final permission totals after Task 1:**
- admin: 163 (all)
- pricing_manager: 57 (9 on Phase 5 + 48 pre-existing: Product CRUD + PricingRule + ProductVariant + ProductOverride + ImportIssue + sync_run view)
- sales: 6 (2 competitor_price view + 2 competitor_ingest_run view + 2 crm_push_log view)
- read_only: 28 (view_% wildcard; NO Phase 5 perms because nothing in Phase 5 was assigned to read_only)

### SuggestionResource kind-specific Approve actions

**margin_change modal** renders old→new margin delta from the D-07 FROZEN evidence JSON:

```
SKU POP-SKU (vs Acme): margin 5000 bps → 7000 bps (Δ +2000 bps).
Approving updates the PricingRule; PricingRuleChanged fires for downstream recompute.
```

Confirming dispatches `ApplySuggestionJob::dispatch($record->id)` → resolves `MarginChangeApplier` (Plan 05-03) → `PricingRule::update` → observer fires `PricingRuleChanged`.

**new_product_opportunity modal** renders supporting_competitors count + explicit Phase 5/6 split:

```
SKU NEW-OPP-1 tracked by 3 competitor(s). Phase 5 applier is a stub;
Phase 6 wires supplier-request-list integration.
```

Confirming dispatches `ApplySuggestionJob` → resolves `NewProductOpportunityApplier` stub (Plan 05-02) which logs `new_product_opportunity.stub_applied` + returns `{phase_5_stub: true, sku, applied_at, applier, note}`.

### supporting_competitors badge column (Filament v3 kind-aware rendering)

First use of `->state(fn ($record) => $record->kind === 'X' ? int : null)` in this codebase. Renders as a colored badge for new_product_opportunity rows, `—` placeholder for every other kind. Zero DB change — reads directly from evidence JSON via `data_get`.

### AlertRecipientResource toggle — third `receives_*` flag

After Phase 2 `receives_sync_reports` (Plan 02-04 D-08) + Phase 4 `receives_crm_alerts` (Plan 04-03 D-12), Phase 5 adds `receives_competitor_alerts` via Toggle field + IconColumn. Pattern is now well-established; future Alert categories follow the same 3-step recipe (column + model cast/scope + Filament toggle).

## Task Commits

1. **Task 1:** 3 Filament Resources + shield:generate + P5-F restoration + RolePermissionSeeder + DatabaseSeeder + access matrix test + shield restoration protocol test — `dba497c`
2. **Task 2:** SuggestionResource kind-specific approve actions + supporting_competitors column + AlertRecipientResource toggle + 3 Pest test files — `3f031c9`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] RolePermissionSeeder LIKE patterns matched TOO BROADLY due to MySQL `_` single-char wildcard**

- **Found during:** Task 1 first dry-run of the seeder — `pricing_manager` ended up with 66 permissions including `create_csv::parse::error`, `delete_csv::parse::error`, `force_delete_csv::parse::error`, etc. D-04 + Plan 05-04a truth-list grants pricing_manager VIEW + UPDATE only.
- **Issue:** MySQL's LIKE wildcard `_` matches ANY single character (unlike shell globs where `_` is literal). The pattern `%_csv_parse_error` was intended to match `view_csv_parse_error` (with the `_` before "csv" being the prefix separator). But the `_` is a wildcard — so `%_csv_parse_error` also matches `create_csv::parse::error`, `delete_csv_parse_error`, `force_delete_csv_parse_error`, etc. Every action got attached because every action name has ANY single char before `csv_parse_error` / `csv::parse::error`.
- **Fix:** Replaced LIKE patterns with explicit `->whereIn([...])` enumeration for Phase 5 resources with action-level grants (csv_parse_error view+update only, competitor_csv_mapping view+update only). Each permission listed twice — once with underscore separator (`update_csv_parse_error`), once with Shield's observed `::` separator (`update_csv::parse::error`) for forward-compat.
- **Files modified:** `database/seeders/RolePermissionSeeder.php`
- **Verification:** Re-seeded → `pricing_manager` count dropped 66→57; `hasPermissionTo('create_csv::parse::error')` → false; `hasPermissionTo('update_csv::parse::error')` → true.
- **Committed in:** `dba497c` (Task 1)

**2. [Rule 3 — Blocking] shield:generate overwrote 13 hand-written policies + created 1 stub (P5-F)**

- **Found during:** Task 1 Step 3 (`php artisan shield:generate --all --panel=admin --no-interaction`) — `git status` showed 13 modified `.php` files under `app/Domain/*/Policies/` + `app/Policies/RolePolicy.php` + an untracked `app/Foundation/Integration/` tree.
- **Issue:** Shield 3.9.10 regenerates every discoverable Policy on `shield:generate --all`. Hand-written hasRole checks regressed to permission-based stubs; RolePolicy re-introduced `{{ ForceDelete }}`-family placeholder literals (Phase 1 Plan 02 + Phase 4 Plan 04 precedent). Additionally Shield auto-discovered the `IntegrationEvent` model and wrote a new Policy stub at `app/Foundation/Integration/Policies/IntegrationEventPolicy.php` that would conflict with the hand-written `CrmPushLogPolicy` via Laravel's auto-discovery.
- **Fix:** Applied the P5-F restoration protocol verbatim from Phase 4 Plan 04's log:
  1. `git checkout HEAD --` on all 13 modified policies
  2. `rm -rf app/Foundation/Integration/Policies` to remove the stub dir
  3. Re-grep for `{{ ` → 0 matches
- **Files modified:** 13 policies restored; `app/Foundation/Integration/Policies/` removed
- **Verification:** `grep -rln '{{ ' app/Policies/ app/Domain/*/Policies/` → empty; PolicyTemplateIntegrityTest green with floor=21; all 5 Phase 5 Competitor policies resolve to their hand-written Domain classes via Gate::getPolicyFor().
- **Committed in:** `dba497c` (Task 1)

**3. [Rule 2 — Missing Critical] DatabaseSeeder needed belt-and-braces UPDATE for ops@meetingstore.co.uk.receives_competitor_alerts**

- **Found during:** Task 1 plan-reading — Plan truth-list says "DatabaseSeeder upserts ops@meetingstore.co.uk with receives_competitor_alerts=true (idempotent via firstOrCreate + follow-up update) so existing seeded rows gain the flag on re-seed".
- **Issue:** `AlertRecipientSeeder::run()` already passes `receives_competitor_alerts => true` on `firstOrCreate`, BUT if the row existed pre-Phase-5 (common on long-lived dev DBs that already have `ops@meetingstore.co.uk`), the firstOrCreate short-circuits and the flag stays at its pre-Phase-5 default (false). Plan 05-01's migration does force-UPDATE the column, but production migrations run ONCE; subsequent `db:seed` invocations would only catch new rows.
- **Fix:** Added an idempotent `AlertRecipient::where('email', 'ops@...')->update([...])` in `DatabaseSeeder::run()` AFTER the seeder chain runs. Safe to re-run — re-executing UPDATE on a row that already has the flag set is a no-op at the DB level.
- **Files modified:** `database/seeders/DatabaseSeeder.php`
- **Verification:** Re-running `php artisan db:seed` twice produces identical AlertRecipient row counts; `receives_competitor_alerts` stays true through every re-seed.
- **Committed in:** `dba497c` (Task 1)

**4. [Rule 1 — Bug] ApplySuggestionJob::dispatch signature is `string $suggestionId`, not a Suggestion model**

- **Found during:** Task 2 initial SuggestionResource extension — the plan's prose example `ApplySuggestionJob::dispatch($record)` would have resulted in a typed-property-hydration error at job execution time because the constructor declares `public readonly string $suggestionId`.
- **Issue:** Plan prose pseudocode used `$record` (Suggestion model); actual constructor signature takes a ULID string.
- **Fix:** Used `$record->id` in every dispatch call (3 places across approve + approve_margin_change + approve_new_product_opportunity actions). Matches the existing pattern on the 'approve' + 'replay' actions (which used $record->id already).
- **Files modified:** `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`
- **Verification:** MarginChangeSuggestionApproveActionTest asserts `Queue::assertPushed(ApplySuggestionJob::class)` → job queued with correct $suggestionId string.
- **Committed in:** `3f031c9` (Task 2)

---

**Total deviations:** 4 auto-fixed (2× Rule 1 bug, 1× Rule 2 missing-critical, 1× Rule 3 blocking). All required for correctness; no Rule 4 architectural asks. The LIKE-wildcard bug (Deviation #1) is the most notable catch — it could have silently granted pricing_manager destructive permissions in production if the plan had shipped as originally drafted. The explicit whitelist approach is now the Phase-5-onwards pattern for any resource with mixed grants.

## Authentication Gates

None — this plan is pure Filament UI + RBAC seeding.

## Filament v3 TableAction Visibility Quirks Discovered

- **`->visible()` is evaluated per-row** — kind-specific actions work cleanly because each closure inspects `$r->kind`. No quirks. The action renders/hides at table-refresh time, no page reload needed.
- **`->authorize()` runs BEFORE `->visible()`** at the POST level — defence-in-depth pattern means a crafted request trying to invoke approve_margin_change on a different kind would still 403 via the admin hasRole check. The visibility gate is UX polish; authorize is the security boundary.
- **`assertTableActionHidden` requires the Livewire test context** — direct unit testing of action closures isn't practical; the matrix tests spin up ListSuggestions as a Livewire component and assert visibility per-record. Performance: ~0.7s per assertion (dominated by Livewire + Filament bootstrap).
- **`state()` closure column rendering is NULL-safe** — returning null from the state closure renders the placeholder; no "call to method on null" trip. Works reliably for kind-aware JSON accessor columns.

## supporting_competitors Column — JSON-path accessor outcome

Tested 2 approaches:
1. **Rejected:** `TextColumn::make('evidence.supporting_competitors')` — Filament's dot-notation accessor fails on JSON columns (it's Eloquent-relation-only; `evidence` is an array cast, not a relation).
2. **Adopted:** `TextColumn::make('supporting_competitors')->state(fn ($r) => ...)` — works cleanly. The `make()` name is decorative (no column lookup); `->state()` provides the actual value.

No custom Suggestion model accessor needed. Evidence JSON stays UI-agnostic.

## Scope Boundary Note

**CompetitorResource (CRUD of the `competitors` table itself) is intentionally deferred.** Per revision guidance, Plan 05-04a ships 3 READ-ONLY Resources for the downstream tables (prices / ingest runs / parse errors). The competitors table itself is auto-populated by Plan 05-02's `CompetitorWatchCommand::firstOrCreate` on first-sighting of a new filename prefix (D-01). Admins needing to promote a `status=pending` row to `status=active` use `php artisan tinker` or a targeted follow-up plan pre-Phase-7 if the friction becomes unacceptable.

This is tracked in the plan's covered-by-checker truth-list entry; downstream plans (04b/05) can reference it.

## Known Stubs

**None new in this plan.** The NewProductOpportunityApplier stub (Plan 05-02 D-08) is unchanged — Plan 05-04a wires the UI approve path but the applier body remains a no-op log + return stub. Phase 6 replaces the body when supplier-request-list integration lands.

## Next Phase Readiness

### Plan 05-04b (Filament Pages + stale-feed command) can assume

- 3 Phase 5 Filament Resources exist with `Competitor Intelligence` navigation group — Pages can add themselves to the same group for side-by-side UX.
- `CsvParseErrorResource` is the editable triage surface; a Page-level "Ingest Issues Dashboard" can compose a sidebar with counts per issue_type by querying the table directly (no coupling to the Resource).
- SuggestionResource kind-specific actions pattern is FROZEN — Plan 05-04b shouldn't re-extend SuggestionResource. Any new Suggestion-related UI should be either (a) a dashboard widget consuming the existing evidence JSON, or (b) a new domain-specific Page.
- `receives_competitor_alerts` column is populated and opt-in-flag UI is live; Plan 05-05's StaleFeedAlertNotification can scope by this column without further UI work.

### Plan 05-05 (retention + verification) can assume

- AdminPanelProvider's Competitor Resource discovery path is in place; no panel-provider edits needed.
- The `Competitor Intelligence` navigation group is claimed — any Plan 05-05 Filament surface (unlikely — mostly CLI) would join this group.
- PolicyTemplateIntegrityTest floor is still 21; no policy count change expected in 05-05 (retention is job-level, not policy-level).

### Phase 6 supplier-request-list integration

- `approve_new_product_opportunity` Filament action is the admin-facing trigger. Phase 6 replaces ONLY the `NewProductOpportunityApplier::apply()` body — no UI change needed.
- Evidence JSON carries the full `competitor_sightings` array (D-09 dedup); Phase 6 has every field it needs (sku + supporting_competitors + first_seen_at + per-competitor price + competitor_id + name).

### Phase 7 dashboard widgets

- `supporting_competitors` state() pattern is reusable for any kind-aware column in future Filament surfaces.
- `D-07 evidence JSON FROZEN` + `supporting_competitors FROZEN` + `kind-specific action visibility` are the 3 contracts the dashboard will consume.

## Self-Check: PASSED

- **Created files verified:**
  - 3 Filament Resource classes + 4 Page classes under `app/Domain/Competitor/Filament/Resources/` — FOUND
  - 5 new Pest test files under `tests/Feature/Competitor/` — FOUND

- **Commits verified via `git log --oneline`:**
  - `dba497c feat(05-04a): 3 Competitor Filament Resources + shield:generate restoration + RolePermissionSeeder extension` — FOUND
  - `3f031c9 feat(05-04a): SuggestionResource kind-specific approve actions + AlertRecipient competitor toggle` — FOUND

- **Runtime verification:**
  - `php artisan route:list | grep filament/admin/resources/` — `competitor-prices`, `competitor-ingest-runs`, `csv-parse-errors` all present ✓
  - `grep -rln '{{ ' app/Policies/ app/Domain/*/Policies/` — 0 matches ✓
  - `ls app/Foundation/Integration/Policies/ 2>&1` → no such directory ✓ (stub removal confirmed)
  - `php vendor/bin/pest tests/Feature/Competitor/ tests/Architecture/` — 161 passed / 0 failed / 499 assertions ✓
  - `php vendor/bin/pest --filter="Suggestion|AlertRecipient|Crm"` — 215 passed / 1 skipped / 0 failed / 849 assertions ✓
  - `php vendor/bin/deptrac analyse --no-progress` — 0 violations / 0 warnings / 0 errors / 200 allowed ✓
  - `php artisan db:seed --class=RolePermissionSeeder --force` — `Roles synced: admin=163 perms, pricing_manager=57, sales=6, read_only=28` ✓
  - `php artisan tinker --execute` role matrix spot-checks (pricing_manager update_csv::parse::error = true; sales view_csv::parse::error = false; read_only view_competitor::price = false) all correct ✓

---

*Phase: 05-competitor-analysis*
*Plan: 04a-filament-resources-and-rbac*
*Completed: 2026-04-19*
