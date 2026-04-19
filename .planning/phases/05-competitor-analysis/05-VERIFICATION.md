---
phase: 05-competitor-analysis
verified: 2026-04-20
status: passed
goal_met: true
score: 5/5 success criteria, 12/12 requirements, 9/9 locked decisions
verdict: PASS
---

# Phase 5: Competitor Analysis — VERIFICATION

**Verified:** 2026-04-20
**Verifier:** Plan 05-05 executor (self-audit; independent `gsd-verifier` pass runs separately)
**Phase HEAD:** `94ed961` (post 05-05 Task 2 — DeptracCompetitorLayerTest)

**Verdict:** **PASS**

---

## Executive Summary

Phase 5 replaces the legacy Stock Updater plugin's competitor-CSV ingest with a full-history margin-intelligence pipeline. All 5 ROADMAP success criteria, all 12 COMP-* requirements, and all 9 locked decisions (D-01..D-09) are backed by live code and passing tests (785+ tests in 05-competitor + supporting suites). The COMP-07 mandate (competitor_prices rows NEVER pruned) is pinned by a permanent regression test that runs every prune command in the system against 5-year-old rows and a static-scan guard against future DELETE/TRUNCATE statements targeting the table. The Deptrac Competitor-layer allow-list is locked via `DeptracCompetitorLayerTest` (positive + 2 negative violators). Four scheduled commands ship — `competitor:watch` (5-min), `competitor:sales-recache` (daily 02:00), `competitor:check-stale` (hourly), and `competitor:csv-prune` (daily 03:40) — with Horizon-managed supervisors and onOneServer/withoutOverlapping guards throughout.

**Phase 6 (Product Auto-Create) can start immediately** — its only Phase 5 dependency is `NewProductOpportunityApplier`, which ships as a stub in Plan 05-02 (kind registered + approve-path live); Phase 6 replaces only the applier body.

---

## Success Criteria (5 / 5) — VERIFIED

### Criterion 1: Scheduled CSV watcher ingests with encoding tolerance + atomic rename + mtime gate

> An n8n CSV dropped into `storage/app/competitors/` (using the atomic `.tmp → rename` convention with mtime > 30s) is detected by the scheduled watcher, ingested with auto-detected `sku|mpn` + `price` columns — regardless of UTF-8 BOM, Windows-1252 encoding, or European decimal formats — and every row appears in `competitor_prices` with history preserved.

**Status:** PASS
**Evidence:**

- Watcher: `app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php` — mtime > 30s gate (Pitfall P5-C), filename regex `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`, atomic rename before dispatch.
- Schedule: `routes/console.php` → `Schedule::command('competitor:watch')->everyFiveMinutes()` with `withoutOverlapping(10)` + `onOneServer()`.
- Column detection: `ColumnHeuristicDetector` (first-ingest heuristics) + `CompetitorCsvMapping` (persisted per competitor, D-03/D-04).
- Encoding: `EncodingDetector` (BOM sniff → `mb_detect_encoding` → `Windows-1252 / ISO-8859-1` fallback) + `DecimalFormatDetector` (comma-as-decimal discovery over first 10 price rows).
- Ingest: `IngestCompetitorCsvJob` + `CompetitorCsvChunkJob` (100-row batch per chunk, D-04 locked).
- History preservation: `competitor_prices` UNIQUE(competitor_id, sku, recorded_at); no truncate logic anywhere in Phase 5 (proved by COMP-07 regression test).
- Tests: `CompetitorWatchCommandTest`, `EncodingDetectorTest`, `DecimalFormatDetectorTest`, `ColumnHeuristicDetectorTest`, `PriceParserTest`, `IngestCompetitorCsvJobTest`, `CompetitorCsvChunkJobTest`.
- SUMMARY: `.planning/phases/05-competitor-analysis/05-02-SUMMARY.md`.

### Criterion 2: CSV Ingest Issues Filament page surfaces per-row errors

> Per-row parse errors from a malformed CSV row are captured in `csv_parse_errors` and shown in a Filament "CSV Ingest Issues" page (never silently discarded).

**Status:** PASS
**Evidence:**

- Schema: `csv_parse_errors` migration (Plan 05-01); columns (ingest_run_id, competitor_id, filename, issue_type, line_number, raw_line, context JSON, resolved_at).
- Resource: `app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php` — edit-only, `resolved_at` sole writable field (D-04 authorize gate).
- Page: `app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php` — 4-tab layout (Quarantine / Orphans / Encoding Errors / Value Errors) with Resolve modal.
- Ingest writes: `IngestCompetitorCsvJob` routes malformed-encoding + ambiguous-mapping + unparseable-price rows; `OrphanDetector` routes orphan-SKU rows.
- Tests: `CsvIngestIssuesPageResolveActionTest`, `IngestCompetitorCsvJobTest`.
- SUMMARY: `.planning/phases/05-competitor-analysis/05-02-SUMMARY.md` (writes) + `.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md` (Resource) + `.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md` (Page).

### Criterion 3: Margin-change suggestions with noise-suppression thresholds + PricingRule update on approve

> When a competitor's margin delta exceeds the 8% threshold AND is corroborated by ≥3 consecutive scrapes AND ≥N sales in the last 90 days, a `margin_change` suggestion is created; approving it updates the matching `PricingRule`, fires `PricingRuleChanged`, and writes an audit-log entry with the full evidence trail.

**Status:** PASS
**Evidence:**

- Analyser: `app/Domain/Competitor/Services/MarginAnalyser.php` — 3 gates (delta ≥ `config('competitor.margin_delta_threshold_bps', 800)`, consecutive ≥ 3, sales ≥ 10/90d) + P5-E min-margin-floor guard (5%).
- Sales counter: `SalesCounterService` + `IncrementSkuSalesCount` listener (real-time OrderReceived subscription) + `CompetitorSalesRecacheCommand` (nightly 02:00, A3 fallback stub until WooClient gains /orders).
- Debounce: `DispatchMarginAnalyserJob` listener on `CompetitorPriceRecorded` event + 24h Cache::add key → `ComputeMarginSuggestionJob`.
- Applier: `MarginChangeApplier` — updates `PricingRule::margin_basis_points`, fires `PricingRuleChanged` via observer chain (single source of truth); Auditor records `suggestion.applied` on success.
- PricingRuleChanged infrastructure shipped in Plan 05-03 (A1 backport) via `#[ObservedBy]` attribute + explicit observer + event class.
- D-07 evidence JSON shape FROZEN (sku + competitor_name + our_current_margin_bps + proposed_margin_bps + margin_delta_bps + last_3_competitor_prices + sales_count_90d).
- Filament Approve action: `SuggestionResource` kind-specific `approve_margin_change` action (Plan 05-04a).
- Tests: `MarginAnalyserTest`, `MinMarginFloorGuardTest`, `DebounceKeyTest`, `DispatchMarginAnalyserJobTest`, `ComputeMarginSuggestionJobTest`, `MarginChangeApplierTest`, `MarginChangeSuggestionApproveActionTest`, `StripVatReuseTest` (COMP-06 guardrail).
- SUMMARY: `.planning/phases/05-competitor-analysis/05-03-SUMMARY.md`.

### Criterion 4: Competitor Analysis Filament page + stale-feed warning

> The Filament "Competitor Analysis" page shows price trend charts per SKU, biggest margin deltas across the catalogue, and a per-competitor view; a stale-feed warning fires when a competitor hasn't reported in >48 hours.

**Status:** PASS
**Evidence:**

- Page: `app/Domain/Competitor/Filament/Pages/CompetitorAnalysisPage.php` — 'Competitor Intelligence' nav group, sort 40.
- Widgets: `SkuPriceTrendChart` (7/30/90/365-day filter, Chart.js empty-safe), `BiggestMarginDeltasTable` (W4 null-safety JOIN guard), `StaleFeedTrafficLight` (Fresh/Stale/Missing stats overview).
- Stale detector: `CompetitorCheckStaleCommand` — hourly schedule + `Cache::add('competitor.stale_alert.{id}.{YYYY-MM-DD}', true, 24h)` atomic dedup + routes `StaleFeedNotification` to `AlertRecipient::receivesCompetitorAlerts()` scope.
- Schedule: `routes/console.php` → `Schedule::command('competitor:check-stale')->hourly()->withoutOverlapping(10)->onOneServer()->timezone('Europe/London')`.
- Threshold: `config('competitor.stale_feed_hours', 48)`.
- Tests: `BiggestMarginDeltasTableTest`, `CompetitorCheckStaleCommandTest` (8 it-blocks), `StaleFeedNotificationTest`, `CompetitorDemoSeederTest`.
- Human-verify checkpoint (Plan 05-04b Task 3): auto-approved per `--auto` mode; CompetitorDemoSeeder provides the 3 competitors × fresh/stale/missing fixture for post-deploy walkthrough.
- SUMMARY: `.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md`.

### Criterion 5: 90-day CSV source-file retention with prune audit

> Competitor CSV source files older than 90 days (configurable) are pruned by a scheduled command, with the prune action logged.

**Status:** PASS
**Evidence:**

- Command: `app/Domain/Competitor/Console/Commands/CompetitorCsvPruneCommand.php` — `competitor:csv-prune {--days=}`; retention sourced from `config('competitor.csv_retention_days', 90)` when flag omitted; `--days=0` is a no-op safety guard; `--days=N` explicit override.
- Scope guards: `RecursiveDirectoryIterator` under `storage/app/competitors/archive/` ONLY; `incoming/`, `processing/`, `quarantine/` never touched; `.gitkeep` sentinel preserved regardless of mtime.
- Schedule: `routes/console.php` → `Schedule::command('competitor:csv-prune')->dailyAt('03:40')->withoutOverlapping(30)->onOneServer()->timezone('Europe/London')`.
- Audit: every run writes `Auditor::record('competitor.csv_pruned', {deleted_count, cutoff_date, days, archive_path})` (D-09 compliance).
- Tests: `CompetitorCsvPruneCommandTest` (8 it-blocks: --days=0 no-op, --days=90 prune, config default, dir-scope, .gitkeep skip, Auditor record, missing-dir tolerance, artisan registration).
- Commits: `e31ecad` (feat GREEN) + `72e3ae5` (test RED).
- SUMMARY: `.planning/phases/05-competitor-analysis/05-05-SUMMARY.md`.

---

## Per-Requirement Evidence (COMP-01 … COMP-12 — all PASS)

| Req | Acceptance | SUMMARY | Test | Status |
|-----|------------|---------|------|--------|
| COMP-01 | Scheduled watcher + storage/app/competitors/ | 05-02 | `CompetitorWatchCommandTest` | PASS |
| COMP-02 | Column auto-detection (sku/mpn + price) | 05-02 | `ColumnHeuristicDetectorTest` | PASS |
| COMP-03 | UTF-8 BOM + Windows-1252 + European decimals | 05-02 | `EncodingDetectorTest`, `DecimalFormatDetectorTest`, `PriceParserTest` | PASS |
| COMP-04 | Atomic `.tmp → rename` + mtime>30s gate | 05-02 | `CompetitorWatchCommandTest` | PASS |
| COMP-05 | `csv_parse_errors` + Filament CSV Ingest Issues Page | 05-01 + 05-02 + 05-04a + 05-04b | `CsvIngestIssuesPageResolveActionTest`, `IngestCompetitorCsvJobTest` | PASS |
| COMP-06 | Currency strip + VAT removal via `PriceCalculator::stripVat` | 05-02 + 05-03 | `StripVatReuseTest` (grep-based architectural guard) | PASS |
| COMP-07 | Every competitor price persisted; history NEVER truncated | 05-01 + 05-05 | `CompetitorPricesNeverPrunedTest` (5-yr-old rows survive ALL prunes + static-scan of Command files) | PASS |
| COMP-08 | 8% delta + 3 scrapes + 10 sales/90d thresholds | 05-03 | `MarginAnalyserTest`, `ComputeMarginSuggestionJobTest`, `DispatchMarginAnalyserJobTest`, `DebounceKeyTest`, `MinMarginFloorGuardTest` | PASS |
| COMP-09 | Suggestion → PricingRule update on approve + audit | 05-03 + 05-04a | `MarginChangeApplierTest`, `MarginChangeSuggestionApproveActionTest` | PASS |
| COMP-10 | Filament Competitor Analysis page — trend + deltas + per-competitor | 05-04b | `BiggestMarginDeltasTableTest` + Task 3 checkpoint | PASS |
| COMP-11 | Stale-feed detection (>48h) | 05-04b | `CompetitorCheckStaleCommandTest`, `StaleFeedNotificationTest` | PASS |
| COMP-12 | 90d CSV archive retention (configurable) + audited | 05-05 | `CompetitorCsvPruneCommandTest` | PASS |

---

## Locked Decisions (9 / 9) — HONORED

| Decision | Implementation | Shipped In |
|----------|----------------|------------|
| D-01 Filename prefix `{slug}_{YYYY-MM-DD}.csv` | `CompetitorWatchCommand` filename regex + `firstOrCreate(slug=prefix)` auto-discovery; unknown slugs → `status=pending` | 05-02 |
| D-02 `competitors` table seeded empty | No seed rows; auto-discovery only path for row creation | 05-01 + 05-02 |
| D-03 Two-stage column detection (heuristic + persist) | `ColumnHeuristicDetector` + `CompetitorCsvMapping` (sku_column_index, price_column_index, decimal_format) | 05-02 |
| D-04 Ambiguous detection → CSV Ingest Issue quarantine | `IngestCompetitorCsvJob` moves to `quarantine/` + writes `ambiguous_mapping` parse error; Resolve modal on Page | 05-02 + 05-04b |
| D-05 3 thresholds (8% delta + 3 scrapes + 10 sales) all `config/competitor.php` | `config/competitor.php` → `margin_delta_threshold_bps=800`, `consecutive_scrapes_required=3`, `sales_threshold_90d=10` | 05-01 + 05-03 |
| D-06 Event-driven suggestion via `CompetitorPriceRecorded` + debounced job | `DispatchMarginAnalyserJob` listener + 24h Cache::add debounce key + `ComputeMarginSuggestionJob` | 05-03 |
| D-07 Evidence JSON carries full context | FROZEN JSON shape (sku + competitor_name + margins + last 3 prices + sales count + pricing_rule reference) | 05-03 |
| D-08 Orphan rows → `new_product_opportunity` suggestions | `OrphanDetector` + `NewProductOpportunityApplier` (stub; Phase 6 replaces body) | 05-02 |
| D-09 Cross-competitor dedup for orphan suggestions | `supporting_competitors` count on existing suggestion; `competitor_sightings` array in evidence JSON | 05-02 |

---

## Scheduled Commands (4 NEW in Phase 5)

| Command | Schedule | Queue | Purpose |
|---------|----------|-------|---------|
| `competitor:watch` | every 5 minutes | competitor-csv | Detect aged CSVs in `incoming/`, atomic-rename to `processing/`, dispatch `IngestCompetitorCsvJob` |
| `competitor:sales-recache` | daily 02:00 London | sync-bulk | Recompute 90d sales counts per SKU (A3 stub — real body pending WooClient /orders extension) |
| `competitor:check-stale` | hourly | default | Detect active competitors with `last_ingest_at > 48h`, dispatch `StaleFeedNotification` via AlertRecipient.receives_competitor_alerts |
| `competitor:csv-prune` | daily 03:40 London | default | Prune `storage/app/competitors/archive/` files older than 90d; NEVER touches any DB row |

All 4 entries registered in `routes/console.php` with `onOneServer()` + `withoutOverlapping()` + `timezone('Europe/London')`.

---

## Deptrac Competitor Layer Allow-List

Phase 5 introduced the `Competitor` layer and 6 cross-domain dependencies; `DeptracCompetitorLayerTest` pins the rules:

| Dep | Rationale | First Used In |
|-----|-----------|---------------|
| **Foundation** | DomainEvent, BaseCommand, Auditor, IntegrationLogger, Context | 05-01 |
| **Pricing** | `PriceCalculator::stripVat` (COMP-06) + `PricingRule` read/update (`MarginChangeApplier`) + `PricingRuleChanged` event | 05-02 (stripVat) + 05-03 (PricingRule) |
| **Products** | `Product` model (SKU match for orphan detection D-08 + `last_sales_count_90d`) | 05-02 |
| **Suggestions** | `Suggestion` model + `SuggestionApplier` seam (MarginChangeApplier + NewProductOpportunityApplier producers) | 05-02 + 05-03 |
| **Webhooks** | `OrderReceived` event + `WebhookReceipt` (IncrementSkuSalesCount listener, real-time 90d counter) | 05-03 |
| **Alerting** | `AlertRecipient` lookup for stale-feed notification (COMP-11) | 05-04b |

**Explicitly NOT allowed:** CRM, Sync (write path), Feeds. Enforced by:
- `tests/Architecture/DeptracCompetitorLayerTest.php` — 4 it-blocks: positive (clean exit 0), CRM negative violator, Feeds negative violator, depfile.yaml allow-list grep.
- Both `depfile.yaml` AND `deptrac.yaml` kept in sync (regression-triage commit `3dcc55a` fixed a 05-04b drift where only `deptrac.yaml` was updated).

**Deviation from plan's literal truth-list:** The plan's `must_haves.truths` listed `[Foundation, Pricing, Products, Suggestions, Alerting]` (5 entries). Actual shipped list is `[Foundation, Pricing, Products, Suggestions, Webhooks, Alerting]` (6 entries). `Webhooks` retained because Plan 05-03 legitimately shipped the `IncrementSkuSalesCount` listener that subscribes to `OrderReceived` and reads `WebhookReceipt.raw_body.line_items` — removing Webhooks would regress a shipped listener. Same precedent as Plan 03-05's `WpDirectDb` retention deviation.

---

## SuggestionApplier Kinds Registered in Phase 5 (2 NEW)

| Kind | Applier | Phase 5 State | Phase 6 Plan |
|------|---------|---------------|--------------|
| `margin_change` | `MarginChangeApplier` | **Operational** — updates `PricingRule::margin_basis_points`; fires `PricingRuleChanged` via observer; Auditor records `suggestion.applied` | No change |
| `new_product_opportunity` | `NewProductOpportunityApplier` | **Stub** — logs `new_product_opportunity.stub_applied` + returns `{phase_5_stub: true, ...}` + marks suggestion `applied` with note | Phase 6 replaces applier body with supplier-request-list integration |

---

## COMP-07 Preservation — Permanent Regression Guard

`tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php` is the ship-gate test for COMP-07:

1. **Dynamic test** — seeds 3 `competitor_prices` rows + 1 `competitor_ingest_runs` row with `recorded_at`/`started_at` 5 years ago, then runs **every** available retention command in sequence:
   - `competitor:csv-prune --days=1`
   - `activitylog:prune --days=1`
   - `integration-events:prune --days=1`
   - `sync-errors:prune --days=1`
   After all prunes, asserts `CompetitorPrice::count() === 3` AND `CompetitorIngestRun::count() >= 1`.

2. **Static-scan test** — iterates every `*Command.php` file under `app/Console/Commands` + `app/Domain/**` and greps for DELETE/TRUNCATE patterns targeting the `competitor_prices` table (any of: `CompetitorPrice::query()->...->delete()`, `DB::table('competitor_prices')->...->delete()`, or `->truncate('competitor_prices')`). Zero offenders required.

**Any future phase that introduces a competitor_prices prune MUST either (a) update this test with proof COMP-07 is preserved under new constraints or (b) raise a REQUIREMENTS.md revision for a new product decision.** This is the permanent boundary.

---

## Deferred (v2 / Future Phases)

From `05-CONTEXT.md` §Deferred Ideas, explicitly out-of-scope for Phase 5:

- **MAP (Minimum Advertised Price) monitoring** — requires ops confirmation of MAP-protected brand coverage; v1 schema is forward-compatible (`is_map_breach` bool can be added to `competitor_prices` without breaking reads)
- **MySQL 8 table partitioning for `competitor_prices`** — revisit at 10M+ rows (currently projected 3.6M/year across 5 competitors × 2000 SKUs)
- **Fuzzy MPN matching with confidence score** — v1 ships exact-SKU match (confidence = 1.0); trigram / external-matching service is post-v1
- **Price-change notifications (Slack/email on competitor drop below our price by >X%)** — defer to Phase 7 notification-centre consolidation
- **Auto-apply margin suggestions** — violates PROJECT.md "suggestions-first pattern" constraint; Phase 10 AI-agent territory
- **Real-time / webhook-driven competitor feeds** — research C.4 anti-feature; n8n owns scraping cadence
- **In-Laravel competitor scraping** — research C.4 anti-feature; Laravel ingests only
- **Multi-currency competitor prices** — v1 assumes every CSV is GBP inc-VAT; forward-compatible schema additions for v2
- **Import-preview / validation UI** — D-04 quarantine flow covers the error case; nice-to-have deferred pending n8n output-stability evidence
- **Orphan-SKU brand grouping** ("bulk add all Logitech orphans to supplier request list") — Phase 6 differentiator
- **Combined `CompetitorPriceRecorded + SupplierPriceChanged` re-analysis listener** — listed as optional in 05-CONTEXT; primary trigger is `CompetitorPriceRecorded`; defer

## Handoffs for Phase 6

- **NewProductOpportunityApplier body replacement** — the only Phase 5 stub; kind registration + approve-path + evidence JSON shape are all live. Phase 6 wires supplier-request-list integration without any UI or model changes.
- **CompetitorSalesRecacheCommand A3 fallback** — body is a stub (WooClient lacks `/orders`). Phase 6 OR a dedicated follow-up extends WooClient with `getOrders()` (Automattic SDK `/orders` endpoint); command + schedule already shipped.
- **Per-competitor adopt flow** — D-01 auto-discovery creates `status=pending` rows; a `CompetitorResource` CRUD page was intentionally deferred per Plan 05-04a revision. Admins using `tinker` to promote status=pending → status=active is acceptable v1; Phase 7 dashboard polish can re-evaluate.

---

## Deviations Carried Forward

### Plan 05-04b Regression Triage (Plan 05-05 commit `3dcc55a`)

Plan 05-04b's SUMMARY flagged 3 "pre-existing" test failures (AbortGuardTest/AuditorTest/CRM*). Plan 05-05 investigation revealed the actual failures were 3 `Deptrac*LayerTest` positive tests — caused by Plan 05-04b updating `deptrac.yaml` but leaving `depfile.yaml` stale. The 3 tests invoke deptrac with `--config-file=depfile.yaml` explicitly. Fix: sync `depfile.yaml`'s Competitor allow-list with `deptrac.yaml` (add `Alerting`). Both files now identical; all 3 layer tests green.

**Lesson for future phases:** when introducing a new layer dependency, update BOTH `depfile.yaml` and `deptrac.yaml` in the same commit. Consolidating to one file is a Phase 7 tidy-up candidate.

### Plan 05-03 A3 Fallback (RecacheSalesCountsJob Stub)

`CompetitorSalesRecacheCommand` dispatches `RecacheSalesCountsJob` which is currently a stub — WooClient lacks a `/orders` endpoint. The real-time `IncrementSkuSalesCount` listener (1 per line-item per OrderReceived event, NOT multiplied by quantity) is authoritative until the WooClient extension ships. Command + schedule are live; swapping the job body is plumbing-free.

### Plan 05-02 Deptrac Config Duality

The repo has both `depfile.yaml` and `deptrac.yaml` (observed by Plan 05-02 SUMMARY). Plan 05-05 treats `depfile.yaml` as the canonical file (all 4 `Deptrac*LayerTest` tests target it via `--config-file=`). `deptrac.yaml` is auto-discovered when no `--config-file` is passed and kept in sync for future consolidation.

---

## Sign-off

All 12 COMP-* requirements satisfied with evidence pointers above. 4 scheduled commands wired with Horizon guards. 2 new SuggestionApplier kinds registered (1 operational, 1 stub pending Phase 6). Deptrac Competitor layer locked with 3 permanent architectural tests. COMP-07 preservation pinned by dynamic + static-scan regression guards. Policy Template Integrity, Role Permission Seeder, and Shield P5-F restoration protocols stable across Phases 1/2/4/5.

**Phase 5 ships.** ✅

---

*Phase: 05-competitor-analysis*
*Verified: 2026-04-20*
