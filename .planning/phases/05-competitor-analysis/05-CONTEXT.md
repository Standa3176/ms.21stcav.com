# Phase 5: Competitor Analysis - Context

**Gathered:** 2026-04-19 (auto-mode — recommended options selected without interactive input)
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 5 replaces the legacy Stock Updater plugin's competitor-CSV ingest with a full-history, margin-aware intelligence pipeline. Scope: a scheduled file watcher picks up n8n-dropped CSVs from `storage/app/competitors/` (atomic `.tmp → rename` convention with a >30s mtime gate); an encoding-tolerant parser (UTF-8 BOM, Windows-1252, European decimal formats) auto-detects `sku|mpn` + `price` columns; per-competitor column mappings are persisted after first successful ingest so column-naming drift is handled once not every run; every parsed row lands in `competitor_prices` (never truncated — history is the differentiator); per-row parse errors land in `csv_parse_errors` and surface in a Filament "CSV Ingest Issues" page; a `MarginAnalyser` computes our-margin-at-competitor-price using the Phase 3 `PriceCalculator::stripVat()` helper; noise-suppressed `margin_change` suggestions fire when delta ≥ 8% AND ≥3 consecutive scrapes confirm AND the SKU has ≥10 sales in the last 90 days; orphaned rows (CSV SKUs we don't sell) become `new_product_opportunity` suggestions instead of being silently dropped; approving a margin suggestion updates the matching `PricingRule` (Phase 3 D-06..D-07) and emits `PricingRuleChanged`; a Filament "Competitor Analysis" page shows price-trend charts, biggest deltas across the catalogue, and per-competitor views; stale-feed warnings fire when a competitor hasn't reported in >48h; competitor CSV source files prune after 90 days (configurable) with the prune action audited.

Scope is fixed by ROADMAP.md Phase 5 and REQUIREMENTS.md COMP-01..COMP-12. Discussion resolved research C.2 (per-competitor mapping differentiator) and C.3 gaps (orphan handling, stale-feed detection). MAP-policy monitoring is acknowledged as a research C.3 item but deferred — it requires ops confirmation of which AV brands carry MAP programmes that MeetingStore sells.

</domain>

<decisions>
## Implementation Decisions

### Competitor identity assignment (foundational — every CSV row needs a competitor_id)

- **D-01:** **Filename prefix convention.** n8n drops CSVs with the pattern `{competitor_slug}_{YYYY-MM-DD}.csv` (e.g. `acme_2026-04-19.csv`, `avshop_2026-04-19.csv`). The watcher parses the prefix before the first underscore to resolve `competitor_id` from a `competitors` lookup table. Rejected alternatives: subdirectory-per-competitor (more n8n config surface, no benefit) and in-CSV marker column (brittle if n8n output changes). When a new prefix is seen for the first time, the watcher creates a `competitors` row as `status=pending` and surfaces it in the CSV Ingest Issues page — admin names the competitor + optionally marks them `status=active` before further ingests go live.
- **D-02:** **`competitors` table is seeded empty.** Ops adds each competitor in Filament (`CompetitorResource`, admin + pricing_manager) with display name, slug, website URL, optional MAP-policy notes, active/inactive flag. First-ingest auto-discovery per D-01 creates a stub row; ops fills in details. No hardcoded seed — keeps the app portable and avoids stale competitor names drifting.

### Column auto-detection + per-competitor mapping persistence (research C.2 differentiator)

- **D-03:** **Two-stage detection.** On first ingest for a competitor: header heuristics scan the first row for known column-name patterns (`sku`, `mpn`, `part_no`, `part number`, `product code` for SKU; `price`, `rrp`, `cost`, `£`, `gbp` for price) — case-insensitive, whitespace-tolerant. The resolved `(sku_column_index, price_column_index)` is persisted to a `competitor_csv_mappings` table (one row per competitor). On subsequent ingests, the saved mapping is used directly (fast-path) UNLESS the admin has clicked "Reset mapping" in Filament, which re-runs detection on the next CSV.
- **D-04:** **Detection ambiguity surfaces as a CSV Ingest Issue, NOT a silent dropped file.** If first-ingest header heuristics match zero or multiple candidate columns, the whole CSV is held (moved to `competitors/quarantine/`) and an `csv_parse_errors` row flags the ambiguity. Admin resolves by manually picking the correct columns via a Filament form on the Ingest Issues page. This is the ONLY manual config path — once a mapping exists, the pipeline is fully automated.

### Noise-suppression thresholds for margin-change suggestions (COMP-08)

- **D-05:** **Three thresholds, all configurable via `config/competitor.php`:**
  - `margin_delta_threshold` = 8% (REQUIREMENTS.md default locked)
  - `consecutive_scrapes_required` = 3 (REQUIREMENTS.md default locked — prevents knee-jerk suggestions from a single scrape anomaly)
  - `sales_threshold_90d` = 10 orders (the "N" in REQUIREMENTS.md's "≥N sales" — locked at 10 as v1 default). Rationale: 10 orders across 90 days ≈ real demand, prevents suggesting margin changes on slow-movers where a 20% competitor drop is irrelevant because nobody's buying. Sales count pulled from Woo order lookups joined on our SKU — `SalesCounterService` implements the 90-day window and caches per-SKU counts with 24h TTL (recomputed daily by a scheduled command).
- **D-06:** **Suggestion fires via `MarginAnalyser` listener on a new event `CompetitorPriceRecorded`** (emitted after every row write to `competitor_prices`). Listener debounces — it only dispatches a `ComputeMarginSuggestionJob` once per (competitor_id, sku, day) to avoid N-times-per-CSV analysis. Job checks all three thresholds; if all pass, writes a `suggestions('margin_change')` row with the Phase 1 applier seam. Job runs on `default` queue (not `sync-bulk` — fast + low volume).
- **D-07:** **Suggestion evidence payload carries enough data for admin to decide in-context.** `evidence` JSON includes: last 3 competitor prices + dates, our current sell_price + supplier_price + current margin, proposed new margin (= reverse-engineered from competitor-price target + configurable "beat-competitor" offset, default 1p lower), 90-day sales count, which PricingRule would be updated (name + scope + current margin). `payload` is the apply-action input: `{pricing_rule_id, new_margin_basis_points}`.

### Orphaned-row handling (research C.3 gap resolved — becomes a differentiator)

- **D-08:** **Orphaned CSV rows fire `new_product_opportunity` suggestions, NOT silent drops.** When a row's SKU matches no Woo product (after casing + whitespace normalisation), instead of only logging to `csv_parse_errors`, write a `suggestions('new_product_opportunity')` row with: competitor name, their SKU + price, how many other competitors also track this SKU (cross-competitor signal — if 3+ competitors track a SKU we don't sell, that's a gap worth investigating), and a "Suggest add to supplier request list" applier. Phase 6 (Product Auto-Create) will be the approving consumer of these — Phase 5 ships the producer, leaves the applier as a no-op stub registered against kind `new_product_opportunity` until Phase 6 wires the real one. Rationale: Research C.3 correctly identifies this as converting waste into insight; ops gets "Competitor X is tracking 40 SKUs we don't sell" visibility.
- **D-09:** **Cross-competitor deduplication for orphan suggestions.** Don't spawn one suggestion per (competitor × orphan SKU) — group by SKU. First competitor's sighting creates the suggestion; subsequent competitors tracking the same orphan SKU increment a `supporting_competitors` count on the existing suggestion's evidence JSON. Prevents 5 competitors × 1000 orphans = 5000 noise-suggestions.

### Claude's Discretion

Areas not separately discussed — planner/researcher may pick the default best-practice approach:

- **Stale-feed detection + alerting (COMP-11).** Scheduled daily command `competitor:check-stale` computes `MAX(competitor_prices.recorded_at)` per competitor. If > 48h old AND competitor status = active, write a row to the Phase 7 notification centre (or fire an email via the existing `AlertRecipient` distribution — extend the existing `receives_sync_reports` / `receives_crm_alerts` boolean pattern with `receives_competitor_alerts`). Dashboard tile on the Competitor Analysis page shows a "🟢 N fresh / 🟡 N stale / 🔴 N missing" traffic-light summary.
- **Trend chart time windows (COMP-10).** Default 30d view with toggles for 7d / 30d / 90d / 1y. Uses Filament's built-in Chart widget (Chart.js). Per-SKU chart shows competitor prices + our sell_price overlay. Biggest-delta view is a sortable table filtered to (competitor_id, SKU) pairs where the absolute delta is in the top 50, paginated.
- **CSV retention prune (COMP-12).** `competitor:csv-prune` scheduled daily, default 90 days, configurable via `config/competitor.php => 'csv_retention_days' => 90`. Prunes only the raw CSV files under `storage/app/competitors/archive/`; NEVER touches `competitor_prices` rows (COMP-07 mandate — history never truncated). Prune action writes to `audit_log` with actor=`system` and count of files removed.
- **CSV processing queue routing.** Watcher + chunk-ingest jobs run on `competitor-csv` queue (Phase 1 FOUND-09 supervisor already allocated this queue name). Isolates the long-running CSV parsing from real-time CRM pushes.
- **`MarginAnalyser` uses `PriceCalculator::stripVat()`** (Phase 3 D-05) for removing UK VAT from competitor prices before margin comparison — NEVER duplicate the rounding math. COMP-06 implementation: `$exVatPennies = PriceCalculator::stripVat($competitorGrossPennies)`.
- **`new_product_opportunity` applier is a no-op stub in Phase 5.** Registered against the Phase 1 `SuggestionApplier` seam so the kind is recognised + the Approve action is clickable, but the applier body logs "Phase 6 will wire supplier-request-list integration" and marks the suggestion `applied` with a note. This matches the Phase 4 `crm_push_failed` → `CrmPushRetryApplier` pattern where real appliers ship in later phases.
- **Filament "CSV Ingest Issues" page** (COMP-05) shows: (1) ambiguous-column quarantine rows with a resolve form, (2) orphan-SKU surfaced as a separate tab (links to the new_product_opportunity suggestion), (3) encoding-failure rows with raw-line preview, (4) value-parse failures (non-numeric price, unparseable date). Admin + pricing_manager can view; admin can delete resolved rows.
- **Encoding detection order:** (1) BOM sniff for UTF-8/UTF-16, (2) `mb_detect_encoding` on first 4KB with fallback list `[UTF-8, Windows-1252, ISO-8859-1]`, (3) if ambiguous, assume UTF-8 and log the guess. Decimal-format detection: look for comma-as-decimal pattern (`1.234,56` vs `1,234.56`) on the price column's first 10 non-header rows.
- **Competitor schema: `competitors` table columns** — `id`, `slug` (unique), `name`, `website_url` (nullable), `map_policy_notes` (nullable text), `status` enum(`pending`, `active`, `inactive`), `last_ingest_at` (nullable timestamp, updated on every successful CSV), `is_active` (bool, default true), timestamps. `competitor_prices` columns — `id`, `competitor_id`, `sku` (indexed with competitor_id), `mpn` (nullable), `price_pennies_ex_vat` (int — post-VAT-strip), `price_pennies_gross` (int — raw CSV value), `recorded_at` (timestamp — CSV's row date OR ingest time if absent), `ingest_run_id` (FK to `competitor_ingest_runs`), timestamps. Unique index on `(competitor_id, sku, recorded_at)` prevents same-day duplicate rows if a CSV is accidentally ingested twice.

### Folded Todos

None — no pending todos matched Phase 5 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Foundation (authoritative contracts Phase 5 consumes)

- `.planning/phases/01-foundation/01-CONTEXT.md` — 17 locked decisions (RBAC, correlation_id threading, suggestions seam D-14..D-17, AlertRecipient D-12, retention D-04..D-09)
- `.planning/phases/01-foundation/01-03-SUMMARY.md` — `DomainEvent` base + `Context::hydrated` queue bridge + `Auditor` + `IntegrationLogger` + `BaseCommand` (all Phase 5 jobs/commands use these)
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — `SuggestionApplier` contract (Phase 5 is the SECOND real producer after Phase 4 — registers two kinds: `margin_change`, `new_product_opportunity`)
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — Horizon 7 supervisors; `competitor-csv` queue already allocated; `AlertRecipient` Notifiable for stale-feed alerts

### Phase 2 Supplier Sync (CSV ingest + Filament resource patterns)

- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — D-04 (dry-run-default CLI), D-08 (AlertRecipient.receives_* boolean pattern — Phase 5 extends with `receives_competitor_alerts`)
- `.planning/phases/02-supplier-sync/02-02-SUMMARY.md` — `spatie/simple-excel` ^3.9 already installed; generator-based constant-memory CSV reading is the Phase 5 pattern
- `.planning/phases/02-supplier-sync/02-04-SUMMARY.md` — Filament Resource patterns (SyncRunResource + ImportIssueResource) — Phase 5's `CompetitorIngestRunResource` + `CsvParseErrorResource` follow the same shape
- `.planning/phases/02-supplier-sync/02-05-SUMMARY.md` — PolicyTemplateIntegrityTest promoted to tests/Architecture — new Phase 5 policies auto-audited

### Phase 3 Pricing Engine (margin math + rule resolution)

- `.planning/phases/03-pricing-engine/03-CONTEXT.md` — D-05 `PriceCalculator::stripVat()` ships in Phase 3 specifically so Phase 5 competitor-CSV ingest imports it unchanged. **Phase 5 MUST NOT duplicate VAT-removal math.**
- `.planning/phases/03-pricing-engine/03-02-SUMMARY.md` — `RuleResolver` contract; Phase 5's `MarginChangeApplier` modifies `PricingRule.margin_basis_points` and triggers `ProductPriceChanged` through the existing Phase 3 listener (no duplicate recompute logic)
- `.planning/phases/03-pricing-engine/03-05-SUMMARY.md` — Deptrac `Pricing` layer allow-list — Phase 5's `Competitor` layer allowed to depend on `Pricing` (read rules + stripVat)

### Phase 4 Bitrix24 CRM Sync (suggestions producer pattern)

- `.planning/phases/04-bitrix24-crm-sync/04-CONTEXT.md` — D-12 first real suggestion producer pattern (`CrmPushRetryApplier` registered against kind `crm_push_failed`); Phase 5 follows the identical shape for `MarginChangeApplier` + `NewProductOpportunityApplier`
- `.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — Applier registration in `AppServiceProvider::register`; `SuggestionResource` Replay action pattern — Phase 5's margin-change suggestion gets an "Approve & Apply" action following the same shape

### Project foundations

- `.planning/PROJECT.md` — Core Value (Laravel owns pricing), Constraints (event-driven, audit everything, suggestions pattern); Key Decision "n8n owns scraping, Laravel ingests"
- `.planning/REQUIREMENTS.md` — COMP-01 through COMP-12 acceptance criteria
- `.planning/ROADMAP.md` §Phase 5 — 5 success criteria; depends-on Phases 1+2+3 (suggestions seam + supplier prices + PricingRule)
- `.planning/STATE.md` — Open items: MAP-policy brand coverage (research flag — unresolved, see Deferred Ideas)

### Research artefacts

- `.planning/research/FEATURES.md` §Module C — C.1 brief items, C.2 differentiators (per-competitor mapping — D-03/D-04 implements), C.3 gaps (orphan handling — D-08/D-09 resolves; stale-feed — Claude's Discretion; MAP monitoring — deferred), C.4 anti-features
- `.planning/research/PITFALLS.md` — watch for CSV encoding pitfalls + mid-write file race (COMP-04 atomic rename + mtime gate handles this)
- `.planning/research/STACK.md` — `spatie/simple-excel` already pinned; Filament Chart widget default for trend charts

### No external specs

No ADRs, RFCs, or external spec documents beyond the above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1–4 delivered)

- **`PriceCalculator::stripVat(int $grossPennies, int $vatBasisPoints): int`** (Phase 3 D-05) — Phase 5 uses this for every competitor price before margin comparison. No new VAT math.
- **`spatie/simple-excel` ^3.9** (Phase 2) — generator-based CSV reader; Phase 5's `CompetitorCsvParser` uses `SimpleExcelReader::create($path)->getRows()` for constant-memory ingest.
- **`SuggestionApplier` contract + `ApplySuggestionJob`** (Phase 1 D-17) — register `MarginChangeApplier` against kind `margin_change`, `NewProductOpportunityApplier` (stub in Phase 5, real in Phase 6) against kind `new_product_opportunity`.
- **`DomainEvent` base + `ShouldDispatchAfterCommit`** (Phase 1) — Phase 5's `CompetitorPriceRecorded` + `MarginSuggestionCreated` events extend the same base.
- **`Auditor`** (Phase 1) — logs `CompetitorCsvMapping` + `Competitor` model changes + CSV-prune actions via `LogsActivity` trait on the new models.
- **`IntegrationLogger`** (Phase 1) — not used (Phase 5 is file-ingest, not outbound HTTP).
- **`BaseCommand`** (Phase 1) — `CompetitorWatchCommand`, `CompetitorPruneCommand`, `CompetitorCheckStaleCommand`, `CompetitorSalesRecacheCommand` all extend this.
- **`AlertRecipient` Notifiable** (Phase 1 D-12) — extend with `receives_competitor_alerts` boolean column (Phase 2 D-08 + Phase 4 D-12 pattern).
- **`PricingRule` + `RuleResolver`** (Phase 3) — read-only dependency. `MarginChangeApplier` calls `PricingRule::find($id)->update(['margin_basis_points' => $new])` + fires Phase 3's existing `PricingRuleChanged` event.
- **`ProductResource` + `Product` model** (Phase 2) — SKU matching for orphan detection uses `Product::whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($csvSku))])`.
- **`SuggestionResource`** (Phase 1, extended in Phase 4) — Phase 5's two new kinds get their own Approve action shapes; kind-specific visibility via `->visible(fn ($record) => $record->kind === 'margin_change')` pattern.
- **`competitor-csv` Horizon supervisor** (Phase 1 FOUND-09) — already configured; Phase 5 dispatches onto it.
- **`ThrottledFailedJobNotifier`** (Phase 1) — 5-min dedup on failed ingest jobs prevents alert storms from a corrupt CSV.
- **Shield RBAC pattern** — seeder LIKE patterns (`%_competitor`, `%_competitor_price`, `%_competitor_csv_mapping`, `%_competitor_ingest_run`, `%_csv_parse_error`) auto-attach to admin + pricing_manager roles after `shield:generate`.
- **Filament Chart widget + ApexCharts fallback** (Phase 1 STACK.md) — trend charts default to Chart.js built-in; if density/UX requires, swap to `leandrocfe/filament-apex-charts`.

### Established Patterns (from Phase 1–4 SUMMARY files)

- **Migration timestamps** — Phase 4 used `2026_04_20_*`; Phase 5 starts `2026_04_21_*` (planner picks exact minutes).
- **Domain layout** — `app/Domain/Competitor/` (currently absent) gets populated: `Models/` (`Competitor`, `CompetitorPrice`, `CompetitorCsvMapping`, `CompetitorIngestRun`, `CsvParseError`), `Services/` (`CompetitorCsvParser`, `ColumnHeuristicDetector`, `EncodingDetector`, `MarginAnalyser`, `SalesCounterService`, `OrphanDetector`), `Jobs/` (`IngestCompetitorCsvJob`, `CompetitorCsvChunkJob`, `ComputeMarginSuggestionJob`, `PruneCompetitorCsvsJob`), `Listeners/` (on `CompetitorPriceRecorded`), `Appliers/` (`MarginChangeApplier`, `NewProductOpportunityApplier`), `Events/` (`CompetitorPriceRecorded`, `MarginSuggestionCreated`, `CompetitorCsvIngested`), `Filament/Resources/`, `Filament/Pages/` (CompetitorAnalysisPage + CsvIngestIssuesPage), `Console/Commands/`, `Policies/`.
- **Deptrac layer** — new `Competitor` layer allowed to depend on `Foundation`, `Pricing` (read rules + stripVat), `Products` (SKU matching for orphan detection), `Suggestions`. NOT allowed: `CRM`, `Webhooks`, `Sync` (write path), `Feeds`. Extend `depfile.yaml` + add `DeptracCompetitorLayerTest` at `tests/Architecture/`.
- **Filament Resource + Page pattern** — same Shield + policy pattern as Phase 2/3/4. New Resources: `CompetitorResource`, `CompetitorPriceResource` (read-only, filterable), `CompetitorIngestRunResource`, `CsvParseErrorResource`. New Pages: `CompetitorAnalysisPage` (trend charts + biggest deltas + per-competitor views), `CsvIngestIssuesPage` (quarantine resolution + orphan tab + error log).
- **Policy template integrity** — `tests/Architecture/PolicyTemplateIntegrityTest` auto-checks all Phase 5 policies; floor count bumped.
- **Testing DB** — `meetingstore_ops_testing` MySQL DB (Phase 1 P03). Phase 5 tests follow the same pattern.
- **`->authorize()` on Filament Actions** — applies to "Resolve mapping" action, "Suggest add to supplier list" applier, "Approve margin change" action, "Reset mapping" action, Delete actions.
- **`Context::hydrated` + `correlation_id`** — every CSV ingest starts a correlation-id; it threads through `competitor_ingest_runs`, `competitor_prices` (via FK join through ingest_run_id), suggestions, and audit_log.
- **Watcher scheduling pattern** — `routes/console.php` runs `competitor:watch` every 5 minutes; the command scans `storage/app/competitors/incoming/` for files where mtime >30s, dispatches `IngestCompetitorCsvJob` per file, moves the file to `storage/app/competitors/processing/` atomically before dispatch (double-processing-safe).

### Integration Points

- **Inbound (from filesystem):** n8n drops CSV into `storage/app/competitors/incoming/{competitor_slug}_{YYYY-MM-DD}.csv.tmp` → atomic rename to `.csv` → `CompetitorWatchCommand` (scheduled every 5min) picks up → dispatches `IngestCompetitorCsvJob` per file.
- **Inbound (from Phase 2 SupplierPriceChanged):** optional — if competitor prices for a SKU exist AND our supplier price changes, recompute margin-delta to see if a new suggestion fires. Listener lives in Phase 5, subscribes to Phase 2's existing event. (Defer to Claude's Discretion if this complicates the plan — primary trigger is `CompetitorPriceRecorded`.)
- **Outbound to Phase 3:** `MarginChangeApplier` calls `PricingRule::update()` → Phase 3's `RecomputePriceListener` fires on `PricingRuleChanged` → full price recompute → Phase 2's Woo push. No duplicate wiring.
- **Outbound to Phase 6 (stub for now):** `new_product_opportunity` suggestions — Phase 5 ships the producer + a no-op applier stub; Phase 6 replaces the stub with a real applier that adds the SKU to the supplier-request list.
- **New migrations:** `competitors`, `competitor_prices`, `competitor_csv_mappings`, `competitor_ingest_runs`, `csv_parse_errors`, `alert_recipients.receives_competitor_alerts` column, `products.last_sales_count_cache` column (optional — if SalesCounterService denormalises) + its `computed_at` timestamp.
- **New Filament Resources + Pages:** 4 Resources + 2 Pages (above).
- **New domain:** `app/Domain/Competitor/` populated from zero.

</code_context>

<specifics>
## Specific Ideas

- **Full history is the headline differentiator.** The old Stock Updater plugin truncated competitor prices daily; Phase 5 NEVER truncates `competitor_prices` rows (COMP-07 mandate). Prune applies ONLY to raw CSV source files under `storage/app/competitors/archive/`. Document this prominently in the Filament Competitor Analysis page help text so ops understands the storage cost is intentional.
- **The MarginAnalyser pipeline is event-driven, not polled.** `CompetitorPriceRecorded` → debounced `ComputeMarginSuggestionJob` → threshold check → `suggestions` row. Avoids a nightly batch "scan everything" anti-pattern that's brittle at scale.
- **Sales-threshold 90d window prevents slow-mover noise.** The 10-orders-in-90-days gate is the tightest noise filter. Cache per-SKU 90d counts with a scheduled `competitor:sales-recache` command (daily) so the threshold check is a lookup, not a live aggregate query. Recache job runs on `sync-bulk` queue to avoid starving competitor ingests.
- **Orphan suggestion consolidation is critical.** Without D-09 cross-competitor deduplication, 5 competitors × 1000 orphan SKUs = 5000 suggestions in the inbox — ops revolt. One suggestion per orphan SKU, incrementing `supporting_competitors` count on the existing row, is the only sustainable shape.
- **Filament "CSV Ingest Issues" page is the only manual config surface in the whole pipeline.** Once mappings are set, everything is automated. Document this in the page help text so ops doesn't expect a "tweak the parser" config file somewhere.
- **Competitor CSV naming convention belongs in a README delivered to the n8n owner.** Create `docs/n8n-integration/README.md` in Phase 5 explaining the `{competitor_slug}_{YYYY-MM-DD}.csv.tmp → rename` convention + the directory layout (`storage/app/competitors/incoming/`), so the n8n scraping config outputs correctly from day one. Analogous to Phase 4's `docs/wordpress-snippets/`.
- **Competitor-prices table will grow fast.** At ~5 competitors × 2000 SKUs × 365 days = 3.6M rows/year. Partitioning by year via MySQL 8 native partitioning is a nice-to-have; document as a post-v1 follow-up in Deferred Ideas. v1 stays flat-table with a (competitor_id, sku, recorded_at) unique index.

</specifics>

<deferred>
## Deferred Ideas

These surfaced during analysis or research C.3 but are explicitly scoped out of Phase 5 to keep the margin-suggestion ship goal tight. They are candidates for dedicated post-v1 phases or Phase 7+ polish work:

- **MAP (Minimum Advertised Price) monitoring.** Research C.3 flags this as "MEDIUM confidence — depends on whether MeetingStore sells MAP-protected brands (Logitech, Poly, Jabra carry MAP programmes)." Ops confirmation required before scoping. Parked as a candidate for a dedicated MAP-monitoring phase if ops confirms catalogue coverage. v1 stays margin-analysis-only; MAP schema is forward-compatible (an `is_map_breach` boolean column can be added to `competitor_prices` later without breaking v1 reads).
- **Real-time / webhook-driven competitor price feeds.** Research C.4 anti-feature — daily CSV is the data contract; n8n owns scraping cadence. Do not rebuild n8n's scheduling in Laravel.
- **In-Laravel competitor scraping.** Research C.4 anti-feature — Laravel ingests only, n8n scrapes. Stay in lane.
- **Fuzzy MPN matching for product match confidence scoring.** Research C.2 differentiator. v1 ships exact-SKU match only (confidence = 1.0); fuzzy-match on MPN/title with a confidence score is a post-v1 enhancement (would use a trigram index or an external matching service).
- **Price-change notifications** (Slack/email when a tracked competitor drops below our price by >X%). Research C.2 differentiator. Defer to Phase 7 notification-centre consolidation.
- **Auto-apply margin suggestions** (skip the suggestions inbox entirely, update `PricingRule` directly when all thresholds pass). Project constraint violation — PROJECT.md mandates "suggestions-first pattern". Any auto-apply is a Phase 10 AI-agent-framework decision, not Phase 5.
- **Custom reporting cadences / Merchant Center / Meta catalog competitor comparison.** v2 Phase 8 channel-feeds territory.
- **MySQL 8 table partitioning for competitor_prices.** 3.6M rows/year; v1 stays unpartitioned. Revisit at 10M+ rows if query performance degrades.
- **Multi-currency competitor prices.** v1 assumes every competitor CSV is GBP inc-VAT. Per-competitor `currency_code` + per-competitor `vat_multiplier` columns are forward-compatible schema additions for v2.
- **`CompetitorPriceRecorded` + `SupplierPriceChanged` combined listener for immediate re-analysis when our supplier price changes.** Listed in Code Context "optional" — defer to planner judgement; primary trigger stays `CompetitorPriceRecorded`.
- **Import-preview / validation UI** (research C.3 gap — "here's what I'd ingest, confirm?"). Nice-to-have; defer if n8n output stability proves high. Quarantine flow in D-04 covers the error case which is the main concern.
- **Orphaned-SKU grouping by brand** (bulk "add all Logitech orphans to supplier request list" action). Phase 6 differentiator.

### Reviewed Todos (not folded)

No pending todos matched Phase 5 scope — none to defer.

</deferred>

---

*Phase: 05-competitor-analysis*
*Context gathered: 2026-04-19 via auto-mode (recommended defaults selected inline)*
