# Phase 5: Competitor Analysis - Research

**Researched:** 2026-04-19
**Domain:** CSV-driven competitor intelligence — file-watcher ingest, margin-delta analysis, noise-suppressed suggestions, Filament trend UI
**Confidence:** HIGH (stack, patterns, and most pitfalls pinned from Phase 1-4 execution; MEDIUM on a few specific implementation heuristics — flagged inline)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Competitor identity assignment**
- **D-01:** Filename-prefix convention — `{competitor_slug}_{YYYY-MM-DD}.csv`. Watcher parses the prefix before the first underscore to resolve `competitor_id` from a `competitors` lookup. New/unknown prefix creates a `competitors` row with `status=pending` and surfaces on the CSV Ingest Issues page.
- **D-02:** `competitors` table is seeded empty. Ops adds each competitor in Filament (`CompetitorResource`, admin + pricing_manager) with display name, slug, website URL, optional MAP notes, active/inactive flag.

**Column auto-detection + per-competitor mapping persistence**
- **D-03:** Two-stage detection. First ingest runs header heuristics (`sku|mpn|part_no|part number|product code` for SKU; `price|rrp|cost|£|gbp` for price) case-insensitive, whitespace-tolerant. Resolved `(sku_column_index, price_column_index)` persists to `competitor_csv_mappings` (one row per competitor). Subsequent ingests use the saved mapping unless admin clicks "Reset mapping".
- **D-04:** Detection ambiguity surfaces as a CSV Ingest Issue — not a silent drop. If first-ingest heuristics match zero or multiple candidate columns, the whole CSV is held under `competitors/quarantine/` and a `csv_parse_errors` row flags the ambiguity. Admin resolves via Filament form. This is the ONLY manual config path.

**Noise-suppression thresholds**
- **D-05:** Three thresholds, all configurable via `config/competitor.php`:
  - `margin_delta_threshold` = 8% (REQUIREMENTS default locked)
  - `consecutive_scrapes_required` = 3
  - `sales_threshold_90d` = 10 orders (v1 default for "≥N sales")
  Sales count pulled from Woo order lookups joined on our SKU — `SalesCounterService` implements the 90-day window + 24h TTL cache.
- **D-06:** Suggestion fires via `MarginAnalyser` listener on a new `CompetitorPriceRecorded` event (emitted after every row write to `competitor_prices`). Listener debounces to dispatch `ComputeMarginSuggestionJob` **once per (competitor_id, sku, day)**. Job runs on `default` queue.
- **D-07:** Suggestion evidence payload carries: last 3 competitor prices + dates, our sell_price + supplier_price + current margin, proposed new margin (reverse-engineered from competitor-price target + configurable "beat-competitor" offset, default 1p lower), 90-day sales count, which PricingRule would be updated. Payload = `{pricing_rule_id, new_margin_basis_points}`.

**Orphaned-row handling**
- **D-08:** Orphaned CSV rows fire `new_product_opportunity` suggestions (not silent drops). Evidence: competitor name, their SKU + price, how many other competitors track this SKU, applier stub. Phase 6 is the approving consumer; Phase 5 ships a no-op applier stub registered against kind `new_product_opportunity`.
- **D-09:** Cross-competitor dedup for orphan suggestions. Group by SKU — first competitor's sighting creates the suggestion; subsequent competitors increment a `supporting_competitors` count on the existing suggestion's evidence JSON.

### Claude's Discretion

- **Stale-feed detection + alerting** (COMP-11) — scheduled `competitor:check-stale` command, 48h threshold, dashboard traffic-light tile, extends `AlertRecipient` with `receives_competitor_alerts` boolean (per Phase 2 D-08 / Phase 4 D-12 pattern).
- **Trend chart time windows** (COMP-10) — default 30d, toggles for 7d/30d/90d/1y. Per-SKU chart shows competitor prices + our sell_price overlay. Biggest-delta view is a sortable table top-50 paginated.
- **CSV retention prune** (COMP-12) — `competitor:csv-prune` daily, default 90d, configurable `config/competitor.php => 'csv_retention_days' => 90`. Prunes only files under `storage/app/competitors/archive/`; NEVER `competitor_prices` rows (COMP-07). Prune writes to `audit_log`.
- **CSV processing queue routing** — watcher + chunk-ingest jobs run on `competitor-csv` queue (Phase 1 FOUND-09 supervisor already allocated).
- **`MarginAnalyser` uses `PriceCalculator::stripVat()`** (Phase 3 D-05) for VAT removal — NEVER duplicate rounding math. COMP-06: `$exVatPennies = PriceCalculator::stripVat($competitorGrossPennies)`.
- **`new_product_opportunity` applier is a no-op stub in Phase 5.** Registered against Phase 1 `SuggestionApplier` seam; body logs "Phase 6 will wire supplier-request-list integration" and marks suggestion `applied`.
- **Filament "CSV Ingest Issues" page** (COMP-05) tabs: quarantine rows with resolve form + orphan-SKU links + encoding-failure rows with raw-line preview + value-parse failures.
- **Encoding detection order:** (1) BOM sniff (UTF-8/UTF-16), (2) `mb_detect_encoding` on first 4KB with fallback list `[UTF-8, Windows-1252, ISO-8859-1]`, (3) ambiguous → assume UTF-8 + log guess. Decimal format: comma-as-decimal vs dot-as-decimal detection from first 10 non-header price rows.
- **Competitor schema:** `competitors(id, slug unique, name, website_url, map_policy_notes, status enum pending|active|inactive, last_ingest_at, is_active, timestamps)`. `competitor_prices(id, competitor_id, sku indexed with competitor_id, mpn, price_pennies_ex_vat, price_pennies_gross, recorded_at, ingest_run_id FK, timestamps)`. Unique index on `(competitor_id, sku, recorded_at)`.

### Deferred Ideas (OUT OF SCOPE)

- MAP (Minimum Advertised Price) monitoring — research C.3 "depends on MS selling MAP-protected brands"; ops confirmation required. v1 schema is forward-compatible (can add `is_map_breach` boolean later).
- Real-time / webhook competitor feeds (anti-feature per research C.4)
- In-Laravel competitor scraping (anti-feature — n8n owns scraping)
- Fuzzy MPN matching (v1 ships exact-SKU match only, confidence = 1.0)
- Price-change notifications (Slack/email) — defer to Phase 7 notification centre
- Auto-apply margin suggestions (violates "suggestions-first" project constraint)
- Merchant Center / Meta catalog comparison (v2 Phase 8)
- MySQL 8 partitioning for competitor_prices (revisit at 10M+ rows)
- Multi-currency competitor prices (v1 = GBP inc-VAT only)
- Combined SupplierPriceChanged + CompetitorPriceRecorded listener (planner judgement)
- Import-preview UI (quarantine flow in D-04 covers the error case)
- Orphan grouping by brand (Phase 6 differentiator)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| COMP-01 | CSVs in `storage/app/competitors/` picked up by scheduled watcher | §1 watcher atomicity, §12 (05-02 plan) |
| COMP-02 | Column auto-detection for `sku`/`mpn` + `price` even when headers vary | §2 encoding + column heuristics |
| COMP-03 | UTF-8 BOM, Windows-1252, European decimal formats — no silent failure | §2 encoding detection, §9 (Pitfall P5-A) |
| COMP-04 | Atomic `.tmp → rename` + mtime > 30s gate prevents mid-write ingest | §1 watcher atomicity + Windows gotchas |
| COMP-05 | Per-row parse errors logged to `csv_parse_errors`, surfaced in Filament | §6 quarantine flow UX |
| COMP-06 | Currency symbol strip + VAT removal via `PriceCalculator::stripVat()` | §7 MarginAnalyser algorithm (imports Phase 3 stripVat) |
| COMP-07 | Every competitor price persisted — history NEVER truncated | §11 retention prune scope (archive only) |
| COMP-08 | `MarginAnalyser` with 8% delta + 3 scrapes + ≥10 sales/90d thresholds | §7 algorithm, §3 SalesCounterService, §8 debounce |
| COMP-09 | Margin-change suggestions → `suggestions` table; approval updates PricingRule + audit | §9 PricingRuleChanged wiring |
| COMP-10 | Filament "Competitor Analysis" page — trend charts + biggest deltas + per-competitor | §4 trend chart rendering |
| COMP-11 | Stale-feed detection warns admin when competitor hasn't reported in >48h | §10 stale-feed command cadence |
| COMP-12 | Competitor CSV files pruned after 90 days (configurable) | §11 retention prune |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- **Stack pins:** Laravel 12, PHP 8.2+ (dev on PHP 8.4.19 via Herd), Filament 3.3, Horizon, MySQL 8+, Redis 7+
- **Woo integration:** REST only, never direct WP DB writes. Enforced by Deptrac `WpDirectDb` deny rule (Phase 2 Plan 05). Phase 5 competitor domain has no Woo write path — but `SalesCounterService` queries `products.last_sales_count_90d` (populated via Phase 2 Woo REST pulls), not Woo DB directly.
- **Event-driven sync:** Emit domain events from day one. Phase 5 adds `CompetitorPriceRecorded`, `MarginSuggestionCreated`, `CompetitorCsvIngested`.
- **Audit everything:** Phase 5 writes via `LogsActivity` trait (Competitor, CompetitorCsvMapping, PricingRule on apply). Prune actions write `audit_log` rows with actor=`system`.
- **Suggestions pattern:** `MarginAnalyser` writes to `suggestions` table with `kind=margin_change`; `OrphanDetector` writes `kind=new_product_opportunity`. Both use Phase 1 D-14 schema + Phase 1 D-17 `SuggestionApplier` seam.
- **Feed abstraction:** Not relevant to Phase 5.
- **Parity first:** Phase 5 replaces the legacy Stock Updater plugin's competitor-CSV ingest; NEVER truncate `competitor_prices` history (COMP-07).
- **GSD Workflow:** All file-changing tools must go through a GSD command; do not bypass `/gsd-execute-phase`.

## Summary

Phase 5 delivers a full-history competitor-intelligence pipeline layered on top of the Phase 1–4 foundation: a scheduled file-watcher that ingests n8n-dropped CSVs with atomic `.tmp → rename` gating, an encoding- and decimal-format-tolerant parser with persisted per-competitor column mappings, a margin-delta analyser that produces noise-suppressed `margin_change` suggestions, a second suggestion producer for orphaned competitor SKUs (`new_product_opportunity`), a Filament "Competitor Analysis" page with Chart.js trend widgets + biggest-delta tables, a stale-feed detector extending `AlertRecipient`, and a 90-day CSV archive prune.

The research scope resolves the planner's remaining unknowns — all nine locked decisions (D-01..D-09) + Claude's Discretion are already settled in CONTEXT.md. Primary research focus was atomic file-watching on Windows dev / Linux prod, encoding + decimal-format detection implementation, `SalesCounterService` denormalisation strategy, Filament 3 Chart.js widget shape, chunked CSV processing via `CompetitorCsvChunkJob`, the reverse-margin calculation algorithm, debounce keying, `PricingRuleChanged` event wiring, stale-feed cadence, prune scope, the quarantine resolve form, Deptrac allow-list, and the n8n integration README.

**Primary recommendation:** Execute as 5 plans following Phase 4's shape — (1) data model + competitor admin, (2) CSV ingest pipeline + encoding/heuristic detection + orphan detector, (3) margin analyser + sales counter + suggestion producers + appliers, (4) Filament analysis page + CSV Issues UI + stale-feed + n8n docs, (5) retention + Deptrac + verification. `MarginAnalyser` uses `PriceCalculator::stripVat()` verbatim; `MarginChangeApplier` mutates `PricingRule::margin_basis_points` and relies on Phase 3's existing `RecomputePriceListener` subscription to `PricingRuleChanged` (emitted by Phase 3 Plan 02 pattern — applier calls `$rule->update([...])` and fires the event manually since Phase 3 doesn't auto-emit on update).

## Standard Stack

### Core (all pre-installed; no new composer packages required)

| Library | Version (verified) | Purpose | Why Standard |
|---------|---------|---------|--------------|
| spatie/simple-excel | 3.9.0 (pinned in composer.json, verified in composer.lock) `[VERIFIED: composer.lock line "version": "3.9.0"]` | Generator-based CSV reader | Pre-installed in Phase 2 Plan 02; handles BOM + delimiter detection out-of-box; generator yields constant memory regardless of file size `[VERIFIED: Phase 2 Plan 02 SUMMARY]` |
| laravel/horizon | ^5.45 | Queue supervisor | `competitor-csv` supervisor already allocated in Phase 1 FOUND-09 (1–2 procs, 600s timeout) `[VERIFIED: Phase 1 Plan 05 SUMMARY]` |
| spatie/laravel-activitylog | ^4.12 | Audit trail | `LogsActivity` trait on new Competitor* models writes to existing `audit_log` table `[CITED: Phase 3 Plan 02 SUMMARY — pattern established]` |
| filament/filament | ^3.3 | Admin panel + Chart widget | Built-in `ChartWidget` uses Chart.js; filterable with date-range toggles; no plugin needed for Phase 5 scope `[CITED: .planning/research/STACK.md §5]` |
| spatie/laravel-permission | ^6.0 | RBAC (via Shield) | Shield auto-generates per-Resource permissions; seeder LIKE patterns (`%_competitor`, `%_competitor_price`, etc.) attach to admin + pricing_manager `[VERIFIED: Phase 1 Plan 02 SUMMARY establishes pattern]` |

### Supporting (already available — no new installs)

| Asset | Location | Purpose |
|-------|----------|---------|
| `PriceCalculator::stripVat` | `app/Domain/Pricing/Services/PriceCalculator.php` | `int $grossPennies, int $vatBasisPoints = 2000 → int $exVatPennies` — integer math, HALF_UP rounding `[VERIFIED: Phase 3 Plan 01 SUMMARY + CONTEXT D-05]` |
| `BaseCommand` | `app/Console/Commands/BaseCommand.php` | Auto-threads `correlation_id` through Context + logs CID to console `[VERIFIED: Phase 1 Plan 03 SUMMARY]` |
| `DomainEvent` base | `app/Foundation/Events/DomainEvent.php` | Implements `ShouldDispatchAfterCommit`; auto-fills `correlation_id` from Context `[VERIFIED: Phase 1 Plan 03]` |
| `Auditor` | `app/Foundation/Audit/Services/Auditor.php` | Records meta-audit rows (prune actions, system events) `[VERIFIED: Phase 1 Plan 03]` |
| `SuggestionApplier` contract + `ApplySuggestionJob` | `app/Domain/Suggestions/Contracts/` + `app/Domain/Suggestions/Jobs/` | Phase 5 registers `MarginChangeApplier` (kind=`margin_change`) + `NewProductOpportunityApplier` stub (kind=`new_product_opportunity`) `[VERIFIED: Phase 1 Plan 04 D-17; Phase 4 Plan 03 shows the pattern]` |
| `AlertRecipient` + `AlertDistribution` | `app/Domain/Alerting/` | Phase 5 extends with `receives_competitor_alerts` column; pass `onlyReceiving: 'receives_competitor_alerts'` to the notifiable `[VERIFIED: Phase 4 Plan 03 SUMMARY established the onlyReceiving pattern]` |
| `ThrottledFailedJobNotifier` | `app/Domain/Alerting/Listeners/` | Catches job failures with 5-min Cache::add dedup — Phase 5 CSV ingest failures flow here automatically `[VERIFIED: Phase 1 Plan 05 SUMMARY]` |
| `competitor-csv` Horizon queue | `config/horizon.php` | 1–2 procs, 600s timeout — already configured `[VERIFIED: Phase 1 Plan 05 SUMMARY]` |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `spatie/simple-excel` | `league/csv` | league/csv has richer encoding hooks but we'd re-implement the generator layer; simple-excel is already pinned + tested in Phase 2 `[CITED: STACK.md §6 — "don't reinvent for a ~50 line save"]` |
| Filament built-in Chart.js | `leandrocfe/filament-apex-charts` ^3.x | ApexCharts has better interactivity (zoom, per-point tooltips) but adds a second chart library; Chart.js meets COMP-10 — escape hatch only if density becomes a UX blocker `[CITED: STACK.md §5]` |
| 24h-denormalised `SalesCounterService` (recommended) | Live Woo REST per analysis | Live lookup is N queries per `ComputeMarginSuggestionJob` run × 100s of SKUs → Woo rate-limit disaster; denormalise once/day into `products.last_sales_count_90d` + `last_sales_count_computed_at` timestamp |
| Redis cache debounce key (recommended) | DB unique-index on `(competitor_id, sku, scrape_date)` | Redis is faster (Cache::add atomic), aligned with Phase 1 D-13 ThrottledFailedJobNotifier pattern; DB-unique path would hammer the DB with 500+ insert attempts per CSV `[ASSUMED]` |

**No composer installs:** All Phase 5 libraries are pre-existing; composer.lock unchanged.

**Version verification:**
- `spatie/simple-excel` 3.9.0 confirmed in composer.lock (installed Phase 2 Plan 02, verified 2026-04-18) `[VERIFIED: grep composer.lock]`
- PHP 8.4.19 dev runtime verified via Herd `[VERIFIED: php --version via Herd]`

## Architecture Patterns

### Recommended Project Structure

```
app/Domain/Competitor/
├── Models/
│   ├── Competitor.php                   # LogsActivity; slug unique; status enum
│   ├── CompetitorPrice.php              # belongsTo competitor + ingestRun; unique (competitor_id, sku, recorded_at)
│   ├── CompetitorCsvMapping.php         # one-row-per-competitor, belongsTo competitor
│   ├── CompetitorIngestRun.php          # mirrors SyncRun from Phase 2 shape
│   └── CsvParseError.php                # belongsTo ingestRun; issue_type enum
├── Services/
│   ├── CompetitorCsvParser.php          # chunked reader using spatie/simple-excel generator
│   ├── ColumnHeuristicDetector.php      # first-ingest header matching
│   ├── EncodingDetector.php             # BOM → mb_detect_encoding → fallback
│   ├── DecimalFormatDetector.php        # dot-as-decimal vs comma-as-decimal (10-row sample)
│   ├── PriceParser.php                  # "£1,234.56" / "1.234,56" → integer pennies (gross)
│   ├── MarginAnalyser.php               # reverse-margin calculation; threshold guards
│   ├── SalesCounterService.php          # 90d lookup with cache column + 24h TTL
│   └── OrphanDetector.php               # cross-competitor dedup logic
├── Jobs/
│   ├── IngestCompetitorCsvJob.php       # per-file entry point; dispatches chunk jobs
│   ├── CompetitorCsvChunkJob.php        # 100-row chunk (mirror Phase 2 SyncChunkJob)
│   ├── ComputeMarginSuggestionJob.php   # debounced per (competitor_id, sku, day)
│   ├── RecacheSalesCountsJob.php        # nightly denormalisation; sync-bulk queue
│   └── PruneCompetitorCsvsJob.php       # retention prune; default queue
├── Listeners/
│   └── DispatchMarginAnalyserJob.php    # subscribes to CompetitorPriceRecorded
├── Appliers/
│   ├── MarginChangeApplier.php          # updates PricingRule; fires PricingRuleChanged
│   └── NewProductOpportunityApplier.php # no-op stub in Phase 5 (Phase 6 wires real)
├── Events/
│   ├── CompetitorPriceRecorded.php
│   ├── MarginSuggestionCreated.php
│   └── CompetitorCsvIngested.php
├── Filament/
│   ├── Resources/
│   │   ├── CompetitorResource.php
│   │   ├── CompetitorPriceResource.php          # read-only, filterable
│   │   ├── CompetitorIngestRunResource.php      # mirrors SyncRunResource
│   │   └── CsvParseErrorResource.php            # mirrors ImportIssueResource
│   └── Pages/
│       ├── CompetitorAnalysisPage.php            # trend charts + biggest deltas + per-competitor views
│       └── CsvIngestIssuesPage.php               # quarantine resolve + orphan tab + error log
├── Console/
│   └── Commands/
│       ├── CompetitorWatchCommand.php           # every 5 min; mtime > 30s gate
│       ├── CompetitorPruneCommand.php           # daily 03:40; Auditor meta-audit
│       ├── CompetitorCheckStaleCommand.php      # hourly; 48h threshold
│       └── CompetitorSalesRecacheCommand.php    # daily 02:00; sync-bulk queue
└── Policies/
    ├── CompetitorPolicy.php
    ├── CompetitorPricePolicy.php
    ├── CompetitorCsvMappingPolicy.php
    ├── CompetitorIngestRunPolicy.php
    └── CsvParseErrorPolicy.php

storage/app/competitors/
├── incoming/        # n8n drops here: {slug}_{YYYY-MM-DD}.csv.tmp → rename to .csv
├── processing/      # atomic move destination before dispatch (double-processing safe)
├── archive/         # post-ingest location; pruned at 90 days
└── quarantine/      # ambiguous-first-ingest CSVs awaiting admin resolve

docs/n8n-integration/
└── README.md        # filename convention, expected column patterns, directory layout
```

### Pattern 1: Scheduled Watcher with Atomic Move (COMP-01 + COMP-04)

**What:** Laravel scheduler runs `competitor:watch` every 5 minutes; command scans `storage/app/competitors/incoming/` for `.csv` files (never `.tmp`) where `filemtime > 30s old`, atomically moves each to `processing/`, dispatches `IngestCompetitorCsvJob` per file.

**When to use:** File-watcher pattern whenever an external producer (n8n) drops files and mid-write races must be prevented.

**Example:**
```php
// Source: adapted from .planning/research/PITFALLS.md Pitfall 9 + Phase 1 Plan 05 pattern
class CompetitorWatchCommand extends BaseCommand
{
    public function perform(): int
    {
        $dir = Storage::disk('local')->path('competitors/incoming');
        $cutoff = now()->subSeconds(30)->timestamp;

        foreach (glob($dir . '/*.csv') as $path) {
            if (str_ends_with($path, '.tmp')) continue;           // mid-write guard
            if (filemtime($path) > $cutoff) continue;              // mtime > 30s guard
            if (! $this->isAtomicallyMovable($path)) continue;     // Windows handle check

            $dest = $this->moveToProcessing($path);                // rename() is atomic on same volume
            IngestCompetitorCsvJob::dispatch($dest)->onQueue('competitor-csv');
        }
        return 0;
    }
}
```

Scheduler entry in `routes/console.php`:
```php
Schedule::command('competitor:watch')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)     // mutex — 10 min safety window
    ->onOneServer();
```

### Pattern 2: Chunked CSV Ingest (COMP-07 + large files)

**What:** `IngestCompetitorCsvJob` opens the file with `spatie/simple-excel`'s generator, counts total rows, dispatches `CompetitorCsvChunkJob` per 100-row batch. Each chunk job writes to `competitor_prices` + emits `CompetitorPriceRecorded` per row.

**When to use:** Whenever CSV row count × per-row work might exceed worker timeout. Phase 2's `SyncChunkJob` follows this exact shape `[VERIFIED: Phase 2 Plan 03 SUMMARY]`.

**Example:**
```php
// Source: adapted from Phase 2 SyncChunkJob pattern + .planning/research/STACK.md §6
use Spatie\SimpleExcel\SimpleExcelReader;

// In IngestCompetitorCsvJob::handle()
$reader = SimpleExcelReader::create($path)->useDelimiter($delimiter);
$mapping = $this->resolveMapping($competitor, $reader);          // D-03 persistence or D-04 quarantine

$buffer = [];
foreach ($reader->getRows() as $index => $row) {
    $buffer[] = $row;
    if (count($buffer) >= 100) {
        CompetitorCsvChunkJob::dispatch($ingestRun->id, $mapping, $buffer)->onQueue('competitor-csv');
        $buffer = [];
    }
}
if ($buffer) CompetitorCsvChunkJob::dispatch($ingestRun->id, $mapping, $buffer)->onQueue('competitor-csv');
```

### Pattern 3: Event-Driven Debounced Margin Analyser (D-06 + COMP-08)

**What:** Per-row `CompetitorPriceRecorded` event → `DispatchMarginAnalyserJob` listener → `Cache::add($key, true, 24h)` where `$key = "competitor.analyser.debounce.{competitor_id}.{sku}.{YYYY-MM-DD}"`. If `add` returns `false`, the lock is held and the listener exits silently. Otherwise dispatch `ComputeMarginSuggestionJob` on `default` queue.

**When to use:** Event-driven suggestion producers where events fire more often than suggestions should.

**Example:**
```php
// Source: adapted from Phase 1 Plan 05 ThrottledFailedJobNotifier + Phase 5 CONTEXT D-06
class DispatchMarginAnalyserJob implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(CompetitorPriceRecorded $event): void
    {
        $key = sprintf(
            'competitor.analyser.debounce.%d.%s.%s',
            $event->competitorId,
            $event->sku,
            now()->format('Y-m-d'),
        );

        if (! Cache::add($key, true, now()->addHours(24))) {
            return;                                        // another listener already dispatched today
        }

        ComputeMarginSuggestionJob::dispatch(
            competitorId: $event->competitorId,
            sku: $event->sku,
        )->onQueue('default');
    }
}
```

### Anti-Patterns to Avoid

- **Per-row CSV ingest job (one job per row):** Horizon queue depth explodes; 50k-row CSV = 50k jobs backed up. Chunk size 100 keeps queue healthy.
- **Live Woo REST query in `SalesCounterService`:** 500+ Woo REST calls per analysis run trips rate limits. Denormalise nightly + cache.
- **Truncate-on-ingest of `competitor_prices`:** Violates COMP-07. History IS the differentiator (CONTEXT §specifics "headline differentiator").
- **Broad `LIKE '%'` pattern matching for column detection without whitespace/case normalisation:** "SKU " (trailing space) or "SKU Code" would miss the `sku` pattern. Always trim + lowercase before matching.
- **`round()` on float math for VAT:** Pitfall 5 mandate — use integer pennies + `PriceCalculator::stripVat()` ONLY.
- **Overwrite-every-row suggestion dispatch:** Without D-06 debounce, a 2000-row CSV triggers 2000 suggestion-computation jobs per competitor per day.
- **Silent-drop orphan rows:** D-08 explicitly rejects this — every row produces either a `competitor_prices` write OR a `new_product_opportunity` suggestion OR a `csv_parse_errors` row.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSV reading with BOM / encoding / delimiter detection | Native `fgetcsv` loop | `spatie/simple-excel` generator | BOM strip + delimiter auto-detect + constant memory; native loop is the vector for Pitfall 9 silent-failure bugs `[CITED: PITFALLS.md Pitfall 9]` |
| VAT removal math | `$exVat = $gross / 1.2` (float) | `PriceCalculator::stripVat($grossPennies)` | Integer-pennies, HALF_UP rounding, already golden-fixture tested; any parallel implementation drifts by pennies and creates sub-penny noise suggestions `[CITED: PITFALLS.md Pitfall 5]` |
| Atomic file move | Custom lock file + copy + unlink | PHP native `rename()` on same filesystem | `rename()` is POSIX atomic within a volume; custom locking adds race windows |
| Debounce / throttle | `has + put` cache pattern | `Cache::add($key, 1, $ttl)` atomic get-or-set | Phase 1 Plan 05 established the pattern; `has + put` has a race window where two listeners both pass the `has` check |
| Chart rendering | Custom SVG / JS | Filament 3 `ChartWidget` (Chart.js) | Built-in; handles CSV export, time-range toggles, per-series colours; ApexCharts is the escape hatch only if needed |
| HTTP outbound logging | Manual `Log::info()` | `IntegrationLogger` (Phase 1) | Auto-redacts 6 sensitive headers, threads `correlation_id` from Context, writes to `integration_events` — Phase 5 uses this if the stale-feed check ever evolves to HTTP pings |
| Audit log | `DB::table('audit_log')->insert(...)` | `LogsActivity` trait on the model | Existing pattern; Auditor/trait writes richer activity rows joined with causer + correlation_id |
| Policy authorisation | Inline role check in Resources | `*Policy::methodName` + `->authorize()` on Filament Actions | Phase 1+2+4 set the defence-in-depth standard; `->visible()` alone is bypassable via crafted Livewire calls |
| Alert distribution | Hardcoded email in env | `AlertDistribution` Notifiable with `onlyReceiving` scope | Phase 4 Plan 03 established `onlyReceiving: 'receives_competitor_alerts'`; single class, single scope mechanism, tests can assert independently |
| Cross-competitor dedup on orphans | Create-suggestion-per-sighting | `updateOrCreate` on `(kind, sku)` + increment supporting_competitors in evidence JSON | D-09 locked; prevents 5 competitors × 1000 orphans = 5000 noise-suggestions |

**Key insight:** Every item in this table is a Phase 1–4 pattern that Phase 5 must adopt verbatim. Reimplementing any of them is a silent regression risk — the Phase N+1 summary files document the exact gotchas the Phase N authors hit.

## Common Pitfalls

### Pitfall P5-A: CSV BOM / encoding silent failure

**What goes wrong:** n8n drops a file with UTF-8 BOM (`\xEF\xBB\xBF`). `fgetcsv` (or a misconfigured reader) reads the first header cell as `"\xEF\xBB\xBFsku"` instead of `"sku"`. Header heuristics don't match, column detection fails. File either quarantines (D-04 safe path) or — worse in a hand-rolled parser — silently skips.

**Why it happens:** CSV looks simple. It isn't. BOMs, Windows-1252 £-signs, European comma-as-decimal are all common from real competitor feeds `[CITED: PITFALLS.md Pitfall 9]`.

**How to avoid:**
- `spatie/simple-excel` strips UTF-8 BOM automatically. Verify by unit-testing against a BOM fixture.
- For Windows-1252 / ISO-8859-1, run `mb_detect_encoding` on the first 4KB before parsing; if not UTF-8, `mb_convert_encoding` to UTF-8 → write to a scratch file under `storage/app/competitors/processing/.tmp-{uuid}.csv`, reader opens the scratch.
- Every parse error → `csv_parse_errors` row with `issue_type=encoding_failure` + first 200 chars of the offending line.
- **Detection order recommendation:** (1) read first 3 bytes — if `\xEF\xBB\xBF` → UTF-8-BOM, strip + treat as UTF-8; (2) `\xFF\xFE` or `\xFE\xFF` → UTF-16 (LE/BE) → `mb_convert_encoding` to UTF-8; (3) read first 4KB, call `mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], strict: true)`; (4) if still `false`, assume UTF-8 and log `encoding_detection_ambiguous` at WARNING level.

**Warning signs:** `competitor_prices` row count flat for > 48h when n8n is known running; `csv_parse_errors` empty but ingest_runs show completed; competitor analysis page shows "no data" despite files arriving.

### Pitfall P5-B: European decimal format misparse

**What goes wrong:** Competitor CSV has `"1.234,56"` (European) — float parse reads as `1.234`. We store `123p` instead of `123456p`. Competitor appears to be pricing at £1.23 for a £1,234.56 item. Every downstream comparison fires spurious margin suggestions.

**Why it happens:** PHP `floatval()` uses the locale-agnostic dot-as-decimal convention; European formats fail silently.

**How to avoid:**
- Sample the first 10 non-header rows from the price column; count occurrences of `,` vs `.` before the last digit group.
- Heuristic: if `,` appears exactly once in each sample AND is positioned such that 2 digits follow (`\d,\d{2}$`), treat as comma-as-decimal; else dot-as-decimal.
- `PriceParser::fromString(string $raw, 'comma'|'dot' $decimalMode): ?int`:
  - `comma` mode: strip `.` thousand separators, replace `,` with `.`, multiply by 100, intval.
  - `dot` mode: strip `,` thousand separators, multiply by 100, intval.
- Strip currency symbols (`£`, `GBP`, `€`, whitespace) before parsing.
- Persist the detected format on `competitor_csv_mappings.decimal_format` enum column so subsequent ingests skip redetection (mirror D-03 persistence pattern).

**Warning signs:** Unit tests with European-format fixtures pass; real-world dashboards show competitor prices at 1/100 of expected.

### Pitfall P5-C: Windows dev filesystem mid-write race

**What goes wrong:** Dev on Windows writes to `storage/app/competitors/incoming/acme_2026-04-19.csv` via Explorer drag-drop. Windows holds file handles differently from POSIX — `filemtime` updates as the OS flushes buffers, not on rename. Watcher picks up a file that's still being written, parser gets truncated content.

**Why it happens:** Windows filesystem semantics differ from ext4/xfs. Prod runs Linux VPS (ext4), but dev is Windows 11 per `<env>`.

**How to avoid:**
- The `.tmp → rename` convention is the primary guard (n8n writes `.csv.tmp`, renames to `.csv` when done). Windows `rename()` is atomic on the same volume `[VERIFIED: Windows NTFS MoveFile API]`.
- mtime > 30s gate is the belt-and-braces secondary guard.
- On Windows dev, additionally call `@fopen($path, 'r+')` with `flock(LOCK_EX | LOCK_NB)` — if `flock` fails or `fopen` returns false, another process has the file open → skip this pass.
- Dev workflow: dev drops `.csv.tmp` files first, renames after (same as n8n would). Document in `docs/n8n-integration/README.md`.
- Test: write a Pest feature test that opens a file for write (`fopen` + truncate), runs `competitor:watch`, asserts the file is NOT moved to `processing/`.

**Warning signs:** Dev sees "parse error: unexpected EOF" during manual testing; prod rarely hits this because n8n always uses the `.tmp → rename` pattern.

### Pitfall P5-D: Denormalised sales count drift

**What goes wrong:** `products.last_sales_count_90d` is cached once per day. An ops run in the morning sees `count=9` (below threshold); by afternoon a new order lands making it `10` — but the cache says `9` until tomorrow's recache, so the margin suggestion doesn't fire for that SKU for up to 24h.

**Why it happens:** Denormalisation is an inherent freshness-vs-cost trade.

**How to avoid:**
- Acceptable freshness: 24h for a threshold check is fine — competitor CSVs are daily, so a 1-day lag on a sales threshold is indistinguishable from the ingest cadence itself.
- Schedule `competitor:sales-recache` at 02:00 (before the daily n8n drop window) so the counts are fresh when the first ingest lands.
- For low-sales-count SKUs near the threshold (`8 ≤ count < 10`), optionally: on `OrderReceived` event (Phase 1 already fires this on Woo webhook), increment the SKU's counter in-place so borderline cases are real-time. **Defer this to Phase 6 or a v2 enhancement** — adds coupling for a narrow use case.
- Document the lag in the analysis page help text.

**Warning signs:** Ops reports "that SKU was selling yesterday, why no suggestion?"; check `products.last_sales_count_computed_at` — if > 24h old, the recache command failed.

### Pitfall P5-E: Suggestion inbox flood from low-margin-floor bypass

**What goes wrong:** Competitor drops to a price where our margin would be 2%. `MarginAnalyser` produces a suggestion "drop margin to 2%". Ops approves; Woo now sells at a loss after overheads. Worse: competitor was running a clearance sale, not a sustainable price.

**Why it happens:** No min-margin floor on Phase 5 (Phase 3 CONTEXT D-06 explicitly DEFERRED min-margin-floor to post-v1).

**How to avoid:**
- Add `config/competitor.php => 'min_margin_floor_bps' => 500` (5% floor, configurable).
- In `MarginAnalyser::compute()`: if `proposed_margin_bps < $floor`, DO NOT write the suggestion — log `suggestion_suppressed_low_margin` event with SKU + competitor_price + proposed_margin_bps for ops visibility.
- This is Claude's Discretion under CONTEXT; not a Phase 3 feature regression — just a safety rail Phase 5 adds on its own analyser.
- Unit test: competitor price that would force 3% margin → assert no suggestion written + assert Log::warning called.

**Warning signs:** Ops complains "you suggested we sell at a loss"; activity log has a `margin_change` suggestion with `proposed_margin_bps < 500`.

### Pitfall P5-F: `shield:generate` regenerates Phase 5 policies and destroys hand-written role checks

**What goes wrong:** Phase 5 Plan 04 (Filament UI plan) runs `shield:generate --all` to register `CompetitorResource` + 3 others. Shield 3.9.10 regenerates `CompetitorPolicy`, `CompetitorPriceResourcePolicy`, `CsvParseErrorPolicy`, and also re-damages the previous phases' `RolePolicy` with `{{ Placeholder }}` literals.

**Why it happens:** Phase 1 Plan 02 identified; Phase 2 Plan 04 confirmed; Phase 4 Plan 04 re-confirmed. `shield:generate` is destructive to hand-written policies — every phase that runs it has paid this cost.

**How to avoid:**
- Phase 5 follows the Phase 2 + Phase 4 post-shield:generate restoration protocol:
  1. Pre-flight: capture SHA256 hashes of all existing policies to `.tmp/policy-hashes-pre.txt`.
  2. Run `shield:generate --all --panel=admin --no-interaction`.
  3. `grep -r '{{ ' app/Policies/ app/Domain/*/Policies/` — expect hits; record them.
  4. `git checkout HEAD -- <6+ policy paths>` to restore.
  5. `PolicyTemplateIntegrityTest` (already in `tests/Architecture/` from Phase 2 Plan 05) reruns and asserts zero `{{ ` literals across ALL policies.
- The seeder LIKE patterns in `RolePermissionSeeder` (already has Phase 1–4 patterns) extend with `%_competitor`, `%_competitor_price`, `%_competitor_csv_mapping`, `%_competitor_ingest_run`, `%_csv_parse_error`, PLUS the `::`-style variants for any multi-word Resource name Shield emits.

**Warning signs:** Phase 5 Plan 04 full-suite fails with `PolicyTemplateIntegrityTest` finding literal `{{ ` strings; admin user loses access to previous-phase resources post-deploy.

## Runtime State Inventory

> Phase 5 is additive (new tables, new services, new Filament pages). No existing runtime state is renamed, migrated, or re-keyed. Nothing in any of the five categories below requires migration work.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — all Phase 5 tables are greenfield (`competitors`, `competitor_prices`, `competitor_csv_mappings`, `competitor_ingest_runs`, `csv_parse_errors`). `products.last_sales_count_90d` + `last_sales_count_computed_at` are additive columns on an existing table. | None — new migrations only |
| Live service config | None — no external service configuration (Bitrix / Woo admin / Cloudflare) changes. n8n config change is the n8n OWNER's responsibility, covered by `docs/n8n-integration/README.md`. | Document n8n file-drop convention only |
| OS-registered state | None — Phase 5 adds new Horizon supervisors? **NO** — `competitor-csv` supervisor is already registered in Phase 1 FOUND-09. Scheduler entries added to `routes/console.php` — taken live automatically by existing cron `* * * * * php artisan schedule:run`. | None |
| Secrets/env vars | None — no new external API keys. `config/competitor.php` adds config (min_margin_floor_bps, csv_retention_days, stale_feed_hours) with sensible defaults; no `.env` changes required. | None (optional: document config overrides in ops runbook) |
| Build artifacts | None — no package version pins change, no compiled artifacts. `competitor-csv` queue name already reserved. | None |

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Laravel runtime | ✓ (via Herd) | 8.4.19 | — |
| composer | Lock verification | ✗ in shell but pre-existing lockfile is authoritative | — | composer.lock already pinned |
| Node.js | Vite asset build | ✓ | 24.14.1 | — |
| `spatie/simple-excel` | CSV ingest | ✓ (pinned) | 3.9.0 | — |
| `automattic/woocommerce` | Used by Phase 2; Phase 5 does NOT call Woo directly | ✓ | 3.1.0 | — |
| MySQL 8 | DB for new tables | presumed ✓ (Phase 1–4 shipped) | 8+ | — |
| Redis 7 | `competitor-csv` queue + debounce cache | presumed ✓ (Phase 1–4 shipped) | 7+ | — |
| `storage/app/competitors/` filesystem dirs | CSV drop + processing | N/A — created by `competitor:watch` on first run OR by migration seed | — | Add to `.gitkeep` pattern (`incoming/.gitkeep`, `processing/.gitkeep`, `archive/.gitkeep`, `quarantine/.gitkeep`) |

**Missing dependencies with no fallback:** None blocking.

**Missing dependencies with fallback:** None — all Phase 5 work is internal Laravel code + existing packages.

## Code Examples

### Example 1: Reverse-margin calculation (MarginAnalyser core)

```php
// Source: adapted from Phase 3 CONTEXT D-05 (PriceCalculator::stripVat) + Phase 5 CONTEXT D-07 (suggestion evidence shape)
final readonly class MarginProposal
{
    public function __construct(
        public int $proposedMarginBasisPoints,
        public int $competitorExVatPennies,
        public int $supplierExVatPennies,
        public int $beatByPennies,
    ) {}
}

class MarginAnalyser
{
    public function __construct(
        private PriceCalculator $calculator,
        private int $minMarginFloorBps,            // from config
        private int $beatByPennies,                // from config, default 1 (penny)
    ) {}

    public function computeProposal(
        int $competitorGrossPennies,
        int $supplierExVatPennies,
    ): ?MarginProposal {
        $competitorExVat = $this->calculator->stripVat($competitorGrossPennies);  // Phase 3 D-05 — NEVER reimplement
        $targetSellExVat = $competitorExVat - $this->beatByPennies;

        if ($supplierExVatPennies <= 0) {
            return null;                           // defer to Phase 3's existing SupplierPriceUnusableException guard
        }

        // target_sell = supplier * (1 + margin/10000)
        // margin_bps = ((target_sell - supplier) / supplier) * 10000
        $marginBps = intdiv(($targetSellExVat - $supplierExVatPennies) * 10000, $supplierExVatPennies);

        if ($marginBps < $this->minMarginFloorBps) {
            Log::warning('suggestion_suppressed_low_margin', [
                'supplier_ex_vat_pennies' => $supplierExVatPennies,
                'competitor_ex_vat_pennies' => $competitorExVat,
                'proposed_margin_bps' => $marginBps,
                'floor_bps' => $this->minMarginFloorBps,
            ]);
            return null;                           // Pitfall P5-E guard
        }

        return new MarginProposal(
            proposedMarginBasisPoints: $marginBps,
            competitorExVatPennies: $competitorExVat,
            supplierExVatPennies: $supplierExVatPennies,
            beatByPennies: $this->beatByPennies,
        );
    }
}
```

### Example 2: `CompetitorPriceRecorded` event + listener with debounce

```php
// Source: Phase 1 Plan 05 Cache::add atomic pattern + Phase 5 CONTEXT D-06
namespace App\Domain\Competitor\Events;

final class CompetitorPriceRecorded extends DomainEvent
{
    public function __construct(
        public readonly int $competitorId,
        public readonly string $sku,
        public readonly int $priceGrossPennies,
        public readonly int $priceExVatPennies,
        public readonly int $ingestRunId,
    ) {
        parent::__construct();                     // auto-fills correlation_id from Context
    }
}

namespace App\Domain\Competitor\Listeners;

class DispatchMarginAnalyserJob implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(CompetitorPriceRecorded $event): void
    {
        $today = now()->format('Y-m-d');
        $key = "competitor.analyser.debounce.{$event->competitorId}.{$event->sku}.{$today}";

        if (! Cache::add($key, true, now()->addHours(24))) {
            return;                                // another listener today already dispatched
        }

        ComputeMarginSuggestionJob::dispatch(
            $event->competitorId,
            $event->sku,
        )->onQueue('default');
    }
}
```

### Example 3: Filament `ChartWidget` for per-SKU price trend

```php
// Source: https://filamentphp.com/docs/3.x/widgets/charts (official) + Phase 5 CONTEXT §Claude's Discretion on windows
namespace App\Domain\Competitor\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class SkuPriceTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Competitor Price Trend';
    protected int|string|array $columnSpan = 'full';
    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7'  => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last year',
        ];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $labels = collect(range(0, $days))
            ->map(fn ($i) => now()->subDays($days - $i)->format('Y-m-d'))
            ->toArray();

        $datasets = Competitor::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (Competitor $c) => [
                'label' => $c->name,
                'data' => CompetitorPrice::query()
                    ->where('competitor_id', $c->id)
                    ->where('sku', $this->sku)
                    ->where('recorded_at', '>=', now()->subDays($days))
                    ->get(['recorded_at', 'price_pennies_ex_vat'])
                    ->groupBy(fn ($row) => $row->recorded_at->format('Y-m-d'))
                    ->map(fn ($group) => $group->avg('price_pennies_ex_vat') / 100)
                    ->values()
                    ->toArray(),
            ])
            ->push([
                'label' => 'Our sell_price',
                'data' => /* self series */,
                'borderColor' => '#10b981',
                'borderDash' => [5, 5],
            ])
            ->toArray();

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
```

### Example 4: `MarginChangeApplier` with `PricingRuleChanged` dispatch

```php
// Source: Phase 3 Plan 02 pattern + Phase 4 Plan 03 SuggestionApplier producer shape
namespace App\Domain\Competitor\Appliers;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Events\PricingRuleChanged;       // verify this exists — see §9 below
use App\Domain\Suggestions\Contracts\SuggestionApplier;

class MarginChangeApplier implements SuggestionApplier
{
    public function supports(string $kind): bool
    {
        return $kind === 'margin_change';
    }

    public function apply(Suggestion $suggestion): void
    {
        $payload = $suggestion->payload;
        $rule = PricingRule::findOrFail($payload['pricing_rule_id']);

        $oldMargin = $rule->margin_basis_points;
        $rule->update(['margin_basis_points' => $payload['new_margin_basis_points']]);

        event(new PricingRuleChanged(
            ruleId: $rule->id,
            oldMarginBps: $oldMargin,
            newMarginBps: $rule->margin_basis_points,
        ));

        Auditor::record('competitor.margin_change_applied', [
            'suggestion_id' => $suggestion->id,
            'pricing_rule_id' => $rule->id,
            'old_margin_bps' => $oldMargin,
            'new_margin_bps' => $rule->margin_basis_points,
        ]);
    }
}
```

## State of the Art

Phase 5's stack is all Phase 1–4 assets reused — no library version bumps, no new patterns introduced. The planner can consume STACK.md versions verbatim.

| Old Approach (legacy Stock Updater plugin) | New Approach (Phase 5) | When Changed | Impact |
|--------------------------------------------|------------------------|--------------|--------|
| Truncate competitor prices daily | Full history preserved | Phase 5 v1 | COMP-07 mandate — headline differentiator |
| Single shared column-detection heuristic | Per-competitor persisted mapping | Phase 5 v1 | D-03 research C.2 differentiator |
| Silent-drop orphan rows | `new_product_opportunity` suggestion | Phase 5 v1 | D-08 — converts waste into insight |
| Nightly batch "scan everything" | Event-driven debounced per-SKU | Phase 5 v1 | D-06 — scales to any catalogue size |
| Float math with `$price / 1.2` | Integer pennies via `PriceCalculator::stripVat()` | Phase 3 shipped; Phase 5 imports | Pitfall 5 mitigation — penny-exact parity |

**Deprecated / outdated:**
- The legacy Stock Updater plugin's competitor-CSV ingest lives in the WordPress plugin source referenced in `stock_updater_plugin_architecture` memory; Phase 5 replaces it wholesale. No parallel-run needed on the competitor side — Phase 7 cutover deregisters the legacy plugin's n8n connection when Laravel takes over.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Phase 3 emits `PricingRuleChanged` via an event class that exists (either auto-fired by model observer or hand-dispatched) — `MarginChangeApplier` relies on this to trigger Phase 3's `RecomputePriceListener`. **Action for planner:** read `app/Domain/Pricing/Events/` in Plan 1 opening scan; if `PricingRuleChanged` doesn't exist, Phase 5 ships it. Phase 3 Plan 02 SUMMARY confirms only `ProductPriceChanged` was shipped; `PricingRuleChanged` may NOT exist. | §9 wiring | Margin applier updates `PricingRule` but the recompute never fires; Woo prices don't update. Mitigation: if event doesn't exist, ship in Phase 5 Plan 3 + wire Phase 3's listener to subscribe (back-port). `[ASSUMED]` |
| A2 | Windows `rename()` is atomic on same-volume NTFS (MoveFile API) — prevents mid-write reads on Windows dev env. | §1 watcher atomicity | Minimal — prod runs Linux; dev-only manual testing is the risk. Mitigation: supplementary `flock LOCK_NB` check on Windows. `[VERIFIED: Windows MoveFile API documentation]` |
| A3 | Woo REST `/orders` endpoint supports `after` + `sku` filter so `SalesCounterService` can compute 90d-per-SKU counts with one REST page per SKU (or aggregate via a batched query). **Action for planner:** confirm endpoint supports `after` param + `meta_data.sku` filter before committing to denormalisation strategy. If not, plan 3 switches to a Woo DB read-through — but Woo DB is REST-only per SYNC-04 Deptrac rule. Fallback: require Phase 2's existing sync pipeline to emit `OrderReceived` events that feed a `product_sales_counters` aggregate table. | §3 SalesCounterService | High — if the cheap query path doesn't exist, `SalesCounterService` either does N Woo REST calls per recache (rate-limit risk) or requires a new aggregate table that Phase 5 must ship. Mitigation: the `OrderReceived` event already fires on Woo webhooks (Phase 1 Plan 04) — an in-process counter increment per event is cheap + keeps the cache warm. `[ASSUMED]` |
| A4 | `Competitor` Deptrac layer allow-list `[Foundation, Pricing, Products, Suggestions]` is sufficient — Alerting is NOT needed because the stale-feed notification path uses `AlertDistribution::class` resolved via DI (Foundation layer dependency)? **Check Phase 2 + Phase 4:** both added Alerting to their ruleset. `CompetitorCheckStaleCommand` reads `AlertRecipient::query()->receivesCompetitorAlerts()->get()` — same shape as SyncSupplierCommand emailReport. Conclusion: **Competitor needs Alerting too**. Update: allow-list is `[Foundation, Pricing, Products, Suggestions, Alerting]`. | §12 Deptrac | Medium — Plan 5 `DeptracCompetitorLayerTest` fails if Alerting missing. `[VERIFIED: Phase 2 Plan 05 + Phase 4 Plan 05 patterns]` |
| A5 | Decimal-format detection heuristic (10-row sample, look for `,` position) is reliable enough for the competitor universe MeetingStore scrapes. If competitors use mixed formats within one file (rare), the heuristic may misfire. | §2 decimal-format detection | Low — heuristic can be unit-tested with European + UK fixtures; per-competitor `decimal_format` override in Filament if auto-detection drifts. `[ASSUMED]` |

**If this table is empty:** Not applicable — five genuine assumptions surfaced above.

## Open Questions

1. **Does `PricingRuleChanged` event already exist?**
   - What we know: Phase 3 Plan 02 SUMMARY explicitly ships `ProductPriceChanged`; no mention of `PricingRuleChanged`. Phase 3 CONTEXT D-13 talks about the `ProductPriceChanged` emission on penny-diff. The `RecomputePriceListener` subscribes to `SupplierPriceChanged`, not `PricingRuleChanged`.
   - What's unclear: When a user edits a `PricingRule` in Filament, does recompute fire? If so, through what mechanism?
   - Recommendation: Plan 3 opens with a file-system grep for `PricingRuleChanged` under `app/Domain/Pricing/Events/`. If absent, ship it in Plan 3 (class + observer on `PricingRule::updated` + Phase 3 `RecomputePriceListener` subscription). This is a minor back-port — Phase 3 should arguably have shipped this, but its success criterion 4 is only about `SupplierPriceChanged` → `ProductPriceChanged`.

2. **Does a `SalesCounterService` 90d window already exist anywhere?**
   - What we know: Phase 2 Plan 01 ships `Product` model with pricing fields; no sales-count column seen. Phase 2 `OrderReceived` event fires from the Woo webhook.
   - What's unclear: Whether any Phase 2 or Phase 4 code already aggregates per-SKU sales counts.
   - Recommendation: Phase 5 Plan 3 ships `RecacheSalesCountsJob` that does one bulk Woo REST query (`/orders?after=<90d_ago>&per_page=100&page=N`) with pagination; aggregates into `products.last_sales_count_90d`. If Woo REST can't scope by SKU, aggregate in PHP. Alternative: subscribe `RecordSaleCountListener` to `OrderReceived` events — cheap in-process update. **Preferred: BOTH.** Event-driven real-time increment + nightly recache reconciliation.

3. **Is the seeded empty `competitors` table acceptable day-one UX, or should an admin-visible "empty state" hint exist?**
   - What we know: D-02 locks "seeded empty"; D-01 auto-creates `status=pending` rows on first-ingest file.
   - What's unclear: Whether admin sees an empty Filament `CompetitorResource` day one and thinks the feature is broken.
   - Recommendation: Plan 4 Filament Resource shows an empty-state card: "Drop a CSV in `storage/app/competitors/incoming/` to auto-discover a competitor" — non-blocking but improves day-one UX.

4. **Retention: when the archive is pruned, does the run-log (`competitor_ingest_runs`) survive?**
   - What we know: COMP-07 says competitor_prices never truncated; COMP-12 says CSV source files pruned at 90d.
   - What's unclear: `competitor_ingest_runs` retention — does it stay forever too? Or follow the Phase 1 D-05 `integration_events` 90d policy?
   - Recommendation: `competitor_ingest_runs` kept indefinitely (like sync_runs from Phase 2 — no prune scheduled). `csv_parse_errors` follow Phase 1 D-05 90d pattern since they reference deleted archive files after 90d.

## Validation Architecture

> `workflow.nyquist_validation: false` in `.planning/config.json` — **section skipped per config.**

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | No external auth — n8n writes to filesystem; webhook HMAC unchanged (Phase 1) |
| V3 Session Management | no | No session state |
| V4 Access Control | **yes** | Filament Shield + policies + `->authorize()` on Actions (Phase 1 D-01..D-03 pattern) |
| V5 Input Validation | **yes** | `spatie/simple-excel` for CSV; `PriceParser::fromString(string, mode): ?int` returns null on failure → `csv_parse_errors` row; every row is defensively typed before DB write |
| V6 Cryptography | no | No cryptographic operations in Phase 5 |
| V7 Error Handling | **yes** | Every parse error → `csv_parse_errors` row (COMP-05); no silent drops; failures flow through Phase 1 `ThrottledFailedJobNotifier` |
| V8 Data Protection | **yes** | `competitor_prices` has no PII; `Auditor::record` writes retention-prune actions |
| V12 Files and Resources | **yes** | CSV file ingest is the entry point — see threat patterns below |

### Known Threat Patterns for Phase 5

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Malicious CSV causing OOM via huge rowcount | Denial of Service | Chunked ingest (100-row `CompetitorCsvChunkJob`) + `spatie/simple-excel` generator-based reader — constant memory regardless of row count. 50k-row file processes in 500 dispatched chunks. |
| CSV with formula injection (`=HYPERLINK(...)`) | Tampering | We only read the price column as numeric + sku as string; formulas are never evaluated. If a filename-prefix-as-competitor_slug is malicious (e.g. `../` traversal), reject via whitelist regex: `^[a-z0-9_-]+_\d{4}-\d{2}-\d{2}\.csv$` before ANY file operation. |
| File path traversal via crafted filename from n8n | Tampering | Validate filename matches `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$` regex before resolving `competitor_id`. Reject + quarantine non-matching names. |
| Disk-space-exhaustion attack (endless CSV drops) | Denial of Service | `competitor:csv-prune` at 90d; Horizon worker count caps parallelism; 50k-row cap rejection in ingest command for sanity. Alternatively monitor `df` via prod health checks — out of scope for Phase 5 code. |
| Suggestion payload tampering (admin approves a crafted suggestion that updates wrong rule) | Elevation of Privilege | `MarginChangeApplier` re-fetches the PricingRule by `payload.pricing_rule_id`; policy + Shield permission gates approval (admin + pricing_manager only); audit trail records before/after. No implicit trust of suggestion payload beyond the indexed ID. |
| CSV with SQL-injection-shaped strings in columns | Tampering | Eloquent parameter binding on every `competitor_prices` insert; no raw-SQL string concat. |
| Unauthorised read of competitor prices via public route | Information Disclosure | `CompetitorPriceResource` + `CompetitorAnalysisPage` sit under Filament auth middleware; policies gate view. No public-route exposure. |

## Sources

### Primary (HIGH confidence)

- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/05-competitor-analysis/05-CONTEXT.md` — authoritative user decisions (D-01..D-09 + Claude's Discretion + Deferred Ideas)
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/REQUIREMENTS.md` — COMP-01 through COMP-12 acceptance criteria
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/01-foundation/01-05-SUMMARY.md` — `competitor-csv` supervisor, ThrottledFailedJobNotifier, Cache::add atomic pattern, retention-prune scheduling
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/02-supplier-sync/02-02-SUMMARY.md` — `spatie/simple-excel` 3.9.0 installed + generator pattern
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/02-supplier-sync/02-04-SUMMARY.md` — Filament Resource + RelationManager + Infolist patterns + PolicyTemplateIntegrityTest
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/03-pricing-engine/03-02-SUMMARY.md` — Pure RuleResolver + ProductPriceChanged + RecomputePriceListener (Phase 5's `MarginChangeApplier` must trigger this listener via `PricingRuleChanged`)
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — FIRST real SuggestionApplier producer pattern (CrmPushRetryApplier) — Phase 5 is the SECOND real producer, follows identical shape
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/depfile.yaml` — Deptrac ruleset — confirms Phase 5 `Competitor` layer is already defined (empty; Phase 5 populates + configures allow-list)
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/composer.lock` — `spatie/simple-excel` 3.9.0 verified
- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app/CLAUDE.md` — project constraints (REST only, audit everything, suggestions-first)

### Secondary (MEDIUM confidence)

- `.planning/research/STACK.md` §5 (Filament charts), §6 (CSV ingest patterns)
- `.planning/research/FEATURES.md` §Module C (C.1 brief items, C.2 differentiators, C.3 gaps, C.4 anti-features)
- `.planning/research/PITFALLS.md` Pitfall 5 (VAT rounding), Pitfall 9 (BOM/encoding), Pitfall 14 (competitor suggestion threshold noise), Pitfall 16 (Redis persistence), Pitfall 17 (variable products — Phase 2 already handled)
- [Filament 3.x Chart widget docs](https://filamentphp.com/docs/3.x/widgets/charts) — Chart.js-backed, time-filter support, full server-side render

### Tertiary (LOW confidence — marked for verification)

- Woo REST `/orders` query capability for `after` + SKU filter — **A3 assumption needs Plan 3 verification**
- Phase 3 `PricingRuleChanged` event existence — **A1 assumption needs Plan 3 file-system grep verification**
- Decimal-format 10-row sample heuristic reliability for MeetingStore's actual competitor universe — **A5 assumption (low risk; tunable post-cutover)**

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all Phase 5 libraries are already installed + tested in Phase 1–4
- Architecture: HIGH — 5-plan structure mirrors Phase 2 + Phase 4; domain skeleton (`app/Domain/Competitor/`) already scaffolded
- Pitfalls: HIGH — five Phase 5-specific pitfalls catalogued (P5-A..P5-F) + inherited Phase 1–4 pitfalls pinned
- Plan breakdown: HIGH — 5-plan proposal in §12 maps directly to 12 COMP requirements + 9 locked decisions
- `PricingRuleChanged` wiring: MEDIUM (A1) — depends on Phase 3 state; Plan 3 verifies
- `SalesCounterService` strategy: MEDIUM (A3) — Woo REST capability assumption; event-driven fallback documented

**Research date:** 2026-04-19
**Valid until:** 2026-05-19 (30-day window for stable stack; re-research triggered only if Filament / spatie versions bump or Phase 3 shipping invariants change)

---

## §1 — CSV Watcher Atomicity (COMP-01 + COMP-04)

**Directory layout:**
```
storage/app/competitors/
├── incoming/       # n8n drops {slug}_{YYYY-MM-DD}.csv.tmp → renames to .csv
├── processing/     # atomic move destination (watcher owns this dir)
├── archive/        # success destination; pruned at 90d
└── quarantine/     # ambiguous-first-ingest (D-04)
```

**Watcher command lifecycle:**
1. Scheduler fires `competitor:watch` every 5 minutes (`routes/console.php` per Phase 1 pattern) with `withoutOverlapping(10)`.
2. `glob(incoming/*.csv)` — excludes `.tmp` files inherently.
3. For each file: check `filemtime($path) < (now - 30 seconds)` — the mtime gate.
4. On Windows dev only: additional `flock(LOCK_EX | LOCK_NB)` probe — if any other process has the file open for write, skip.
5. Validate filename regex `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`. Reject → write `csv_parse_errors` row `issue_type=invalid_filename` + move to `quarantine/`.
6. Parse prefix for `competitor_slug`; resolve `competitor_id` via `Competitor::firstOrCreate(['slug' => $slug], ['status' => 'pending', 'name' => $slug])`. First-sighting = `pending` (D-01).
7. Atomic `rename($path, processing/$filename)` — the dispatch lock. If rename fails (file already moved by another worker), skip without error.
8. Dispatch `IngestCompetitorCsvJob($processingPath, $competitorId)` on `competitor-csv` queue.
9. Job completion: move file from `processing/` to `archive/{YYYY-MM-DD}/{filename}`. On failure: move to `quarantine/{YYYY-MM-DD}/{filename}` with `.error.json` sidecar.

**Windows gotchas:**
- `rename()` is atomic on same-volume NTFS (Windows `MoveFile`). Cross-volume rename (e.g. `C:` → `D:`) is a copy+delete — NOT atomic — but all `storage/app/` paths are on the same volume.
- `glob()` on Windows uses case-insensitive matching — the regex filename validator catches unintended case-variants.
- `flock(LOCK_NB)` on Windows works on NTFS but has been historically flaky on network drives — not an issue for local storage.

**Prod (Linux VPS) notes:** `rename()` is POSIX atomic within the same filesystem; `storage/app/competitors/*` should all live on the same ext4 mount.

## §2 — Encoding + Decimal-Format Detection

### Encoding detection (recommended order)

```php
// Source: CONTEXT D-04 Claude's Discretion + Pitfall 9 mitigation pattern
class EncodingDetector
{
    public function detect(string $path): string
    {
        $bytes = file_get_contents($path, false, null, 0, 4096);

        // Level 1: BOM sniff
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) return 'UTF-8';
        if (str_starts_with($bytes, "\xFF\xFE"))      return 'UTF-16LE';
        if (str_starts_with($bytes, "\xFE\xFF"))      return 'UTF-16BE';

        // Level 2: mb_detect_encoding with strict mode
        $detected = mb_detect_encoding($bytes, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], strict: true);
        if ($detected !== false) return $detected;

        // Level 3: fallback + log
        Log::warning('competitor.encoding_detection_ambiguous', [
            'path' => $path,
            'first_bytes_hex' => bin2hex(substr($bytes, 0, 32)),
        ]);
        return 'UTF-8';                          // last-resort
    }
}
```

After detection:
- UTF-8 → read directly via spatie/simple-excel.
- UTF-16LE/BE → `mb_convert_encoding($raw, 'UTF-8', $detected)` → write to scratch file `processing/.tmp-{uuid}.csv` → read from scratch.
- Windows-1252 / ISO-8859-1 → same `mb_convert_encoding` pathway → scratch file.

### Decimal-format detection

```php
// Heuristic: sample first 10 non-header rows from the detected price column
class DecimalFormatDetector
{
    public function detect(iterable $sampleRows, int $priceColIdx): string
    {
        $sample = [];
        foreach ($sampleRows as $i => $row) {
            if ($i === 0) continue;                              // skip header
            if (count($sample) >= 10) break;
            $raw = trim((string) ($row[$priceColIdx] ?? ''));
            if ($raw !== '') $sample[] = $raw;
        }

        $commaAsDecimal = 0;
        $dotAsDecimal = 0;

        foreach ($sample as $value) {
            $value = preg_replace('/[£$€GBP\s]/i', '', $value);

            // Matches "1.234,56" or "1234,56" or "56,78"
            if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $value) || preg_match('/^\d+,\d{1,2}$/', $value)) {
                $commaAsDecimal++;
                continue;
            }

            // Matches "1,234.56" or "1234.56" or "56.78"
            if (preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $value) || preg_match('/^\d+\.\d{1,2}$/', $value)) {
                $dotAsDecimal++;
            }
        }

        return $commaAsDecimal > $dotAsDecimal ? 'comma' : 'dot';   // default 'dot' when ambiguous
    }
}
```

Persist to `competitor_csv_mappings.decimal_format` after first successful detection — skip re-detection on subsequent ingests.

### Integer-pennies conversion

```php
class PriceParser
{
    public function toGrossPennies(string $raw, string $decimalMode): ?int
    {
        $clean = preg_replace('/[£$€GBP\s]/i', '', trim($raw));
        if ($clean === '') return null;

        if ($decimalMode === 'comma') {
            // "1.234,56" → "1234.56"
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            // "1,234.56" → "1234.56"
            $clean = str_replace(',', '', $clean);
        }

        if (! is_numeric($clean)) return null;
        return (int) round(((float) $clean) * 100);   // last rounding — never compound
    }
}
```

## §3 — SalesCounterService Implementation

**Recommendation: hybrid event-driven + nightly reconciliation.**

### Phase 1: schema additions (ship with Plan 1 migrations)

```sql
ALTER TABLE products
  ADD COLUMN last_sales_count_90d INT UNSIGNED NULL,
  ADD COLUMN last_sales_count_computed_at TIMESTAMP NULL;
```

### Phase 2: event-driven increment (Plan 3 listener)

```php
// Listener subscribed to OrderReceived (Phase 1 event)
class IncrementSkuSalesCount implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(OrderReceived $event): void
    {
        $lineItems = data_get($event->payload, 'line_items', []);
        foreach ($lineItems as $item) {
            $sku = data_get($item, 'sku');
            if (! $sku) continue;

            Product::where('sku', $sku)->increment('last_sales_count_90d');
        }
    }
}
```

### Phase 3: nightly reconciliation (Plan 3 command)

```php
class CompetitorSalesRecacheCommand extends BaseCommand
{
    public string $queue = 'sync-bulk';

    public function perform(): int
    {
        // Dispatch batched Woo REST lookups — for each product batch of 100
        Product::chunk(100, function ($products) {
            RecacheSalesCountsJob::dispatch($products->pluck('sku')->all())->onQueue('sync-bulk');
        });
        return 0;
    }
}

// RecacheSalesCountsJob uses the same WooClient as Phase 2
// Scope to last 90 days by calling Woo REST /orders?after=<90d_ago>&per_page=100
```

Reconciliation job reads the authoritative Woo data + overwrites `last_sales_count_90d`. Event-driven increments keep the number fresh between reconciliations; drift is bounded by 24h (the reconciliation interval).

**Scheduling:** `routes/console.php`:
```php
Schedule::command('competitor:sales-recache')->dailyAt('02:00')->onOneServer();
```

**Phase 5 consumer:**
```php
// ComputeMarginSuggestionJob
$salesCount = Product::where('sku', $this->sku)->value('last_sales_count_90d') ?? 0;
if ($salesCount < config('competitor.sales_threshold_90d', 10)) {
    return;                                      // threshold not met; no suggestion
}
```

## §4 — Trend Chart Rendering

**Recommendation: Filament 3 built-in `ChartWidget` with Chart.js.**

Filament 3.3 ships Chart.js-based widgets out of the box — no plugin install, no license considerations, full server-side data render via the `getData()` method `[CITED: https://filamentphp.com/docs/3.x/widgets/charts]`.

**Widget shape (simplified):** see §Code Example 3 above.

**Biggest-delta table (separate Filament Resource or RelationManager):**
```php
// CompetitorAnalysisPage embeds a table widget
class BiggestMarginDeltasTable extends TableWidget
{
    protected function getTableQuery(): Builder
    {
        return CompetitorPrice::query()
            ->selectRaw('competitor_prices.*, products.sell_price, (products.sell_price - competitor_prices.price_pennies_ex_vat) as delta_pennies')
            ->join('products', 'products.sku', '=', 'competitor_prices.sku')
            ->whereIn('competitor_prices.id', fn ($q) =>
                $q->select('id')
                  ->from('competitor_prices')
                  ->whereRaw('recorded_at = (SELECT MAX(recorded_at) FROM competitor_prices cp WHERE cp.competitor_id = competitor_prices.competitor_id AND cp.sku = competitor_prices.sku)')
            )
            ->orderByRaw('ABS(delta_pennies) DESC')
            ->limit(50);                         // top-50 paginated
    }
}
```

**Time-window toggles:** Use Filament's built-in widget filter mechanism (`protected function getFilters()`) — rebuilds the chart on selection without page reload.

**Per-competitor view:** A Filament `Tabs` layout with one tab per active competitor — each tab embeds the SkuPriceTrendChart widget filtered by `competitor_id`.

## §5 — CSV Chunking for Large Files

**Recommendation: 100-row chunks, dispatched via `CompetitorCsvChunkJob` on `competitor-csv` queue.**

Rationale:
- Phase 2's `SyncChunkJob` uses 50-row chunks for Woo API writes (rate-limited); Phase 5 has no external-API rate limit per row, so 100 is a comfortable chunk size — ~500 chunks for a 50k-row file.
- Horizon `competitor-csv` supervisor: 1–2 procs, 600s timeout. Each 100-row chunk processes in ~200ms (DB inserts + event dispatch) → each proc handles ~3k chunks/minute — plenty of headroom.
- Constant memory: `spatie/simple-excel` generator yields one row at a time; chunks buffered at 100 rows max in the dispatcher job.

**Chunk-job shape:**
```php
class CompetitorCsvChunkJob implements ShouldQueue
{
    public int $timeout = 120;
    public int $tries = 2;
    public string $queue = 'competitor-csv';

    public function handle(
        int $ingestRunId,
        array $mapping,                          // [sku_col_idx, price_col_idx, decimal_format]
        array $rows,                             // 100-row batch
        CompetitorCsvRowWriter $writer,
    ): void {
        $run = CompetitorIngestRun::findOrFail($ingestRunId);

        foreach ($rows as $row) {
            $writer->write($run, $mapping, $row);   // handles parse errors + orphan detection
        }
    }
}
```

The writer is the unit that decides: valid row → `competitor_prices` + fire `CompetitorPriceRecorded`; orphan SKU → fire orphan-detector pipeline; parse error → `csv_parse_errors` row.

## §6 — Quarantine Flow UX

**D-04 says quarantined CSVs (ambiguous first-ingest) surface in Filament. Exact resolve-form shape:**

`CsvIngestIssuesPage` (Filament custom Page, not a Resource):

1. Top-level Tabs: `['Quarantine', 'Orphans', 'Encoding Errors', 'Value Errors']`.

2. **Quarantine tab** (most interactive):
   - Table of `csv_parse_errors` rows with `issue_type=ambiguous_mapping`, columns: `competitor_name`, `filename`, `detected_at`, `action_resolve`.
   - Clicking `Resolve` opens a modal:
     - Preview section: first 10 rows of the CSV rendered as a table (read from `quarantine/{filename}` via `Storage::disk('local')->get()`).
     - Form section: two `Select` inputs — "SKU Column" and "Price Column" — populated from the CSV's detected header row.
     - Optional: "Decimal format" radio (`dot` | `comma`) with the 10-row sample results shown.
     - Submit: saves `CompetitorCsvMapping` row (one per competitor) with `sku_column_index`, `price_column_index`, `decimal_format`, moves the CSV back to `incoming/`, re-dispatches `IngestCompetitorCsvJob`, deletes the `csv_parse_errors` row.

3. **Orphans tab:**
   - Table of `new_product_opportunity` suggestions.
   - Each row links to the Suggestion in the main Filament inbox (preserves D-09 evidence JSON — `supporting_competitors` count, cross-competitor dedup).

4. **Encoding Errors tab:**
   - Table of `csv_parse_errors` rows with `issue_type=encoding_failure` — show the raw first-200-char preview from the error row. Read-only (admin manually fixes upstream at n8n).

5. **Value Errors tab:**
   - Table of `csv_parse_errors` rows with `issue_type=unparseable_price` or `issue_type=invalid_sku_format`. Read-only; link back to the ingest run.

**Authorisation:** admin + pricing_manager can view; admin can `Resolve` + Delete. Gated via `CsvParseErrorPolicy` + `->authorize('resolve', $record)` on the Filament Action.

## §7 — MarginAnalyser Algorithm

See §Code Example 1 for full implementation.

**Algorithm summary:**
1. `$competitorExVat = PriceCalculator::stripVat($competitorGrossPennies)` — Phase 3 D-05, NO duplicate math.
2. `$targetSellExVat = $competitorExVat - $beatByPennies` (config, default `1` — 1p lower than competitor).
3. `$marginBps = intdiv(($targetSellExVat - $supplierExVatPennies) * 10000, $supplierExVatPennies)`.
4. Guard: `$marginBps >= $minMarginFloorBps` (default 500 = 5%). Below → log `suggestion_suppressed_low_margin` + return null (Pitfall P5-E).
5. Guard: `$supplierExVatPennies > 0` — reuse Phase 3's `SupplierPriceUnusableException` pattern (don't reimplement).
6. Check 3 thresholds from `ComputeMarginSuggestionJob`:
   - Delta: `|(our_current_margin_bps - $marginBps)| >= config('competitor.margin_delta_threshold_bps', 800)`
   - Consecutive scrapes: Last 3 `competitor_prices` rows for this (competitor_id, sku) all point in the same direction (all below our_sell or all above — use `sign()` comparison).
   - Sales: `Product.last_sales_count_90d >= config('competitor.sales_threshold_90d', 10)`.
7. If ALL pass → write `Suggestion::create(kind: 'margin_change', payload: [...], evidence: [...], correlation_id: Context::get(...))`.
8. Dispatch `MarginSuggestionCreated` event for Phase 7 dashboard consumers.

**Evidence JSON shape (D-07):**
```json
{
  "competitor_id": 42,
  "competitor_name": "AcmeAV",
  "sku": "LOGI-C920",
  "last_3_competitor_prices": [
    {"price_ex_vat_pennies": 8399, "recorded_at": "2026-04-17"},
    {"price_ex_vat_pennies": 8399, "recorded_at": "2026-04-18"},
    {"price_ex_vat_pennies": 8399, "recorded_at": "2026-04-19"}
  ],
  "our_sell_price_pennies": 10500,
  "our_supplier_price_pennies": 6500,
  "our_current_margin_bps": 6153,
  "proposed_margin_bps": 2923,
  "margin_delta_bps": 3230,
  "sales_count_90d": 28,
  "pricing_rule": {
    "id": 17,
    "name": "Default Tier <£100",
    "scope": "default_tier",
    "current_margin_bps": 6153
  },
  "beat_by_pennies": 1
}
```

## §8 — Debounce Strategy

See §Code Example 2 above + Pattern 3.

**Debounce key pattern:** `competitor.analyser.debounce.{competitor_id}.{sku}.{YYYY-MM-DD}`.

**Implementation: `Cache::add($key, true, 24h)`** — atomic get-or-set; returns `false` if lock held. Mirrors Phase 1 Plan 05's `ThrottledFailedJobNotifier` exactly.

**Why 24h TTL:** Competitor CSVs are daily. One analysis per SKU per competitor per day is the right granularity. TTL key format is `YYYY-MM-DD` so the lock naturally expires at midnight anyway — the 24h TTL is belt-and-braces in case of clock skew.

## §9 — `PricingRuleChanged` Wiring

**Assumption to verify (A1):** Phase 3 may not have shipped `PricingRuleChanged` event. Phase 3 Plan 02 SUMMARY only documents `ProductPriceChanged`.

**Recommended Plan 3 flow:**
1. Plan 3 Task 1 opens with `ls app/Domain/Pricing/Events/` + grep.
2. If `PricingRuleChanged.php` doesn't exist:
   - Create it in `app/Domain/Pricing/Events/PricingRuleChanged.php` extending `DomainEvent`. Carry `ruleId`, `oldMarginBps`, `newMarginBps`.
   - Add an observer on `PricingRule` model: on `updated`, if `margin_basis_points` dirty → `event(new PricingRuleChanged(...))`. This makes the event fire automatically whenever ANY code updates a rule (Filament UI, Phase 5 applier, future agents).
   - Subscribe Phase 3's `RecomputePriceListener` — OR a new listener `RecomputeAllAffectedProductsListener` that dispatches a bulk-recompute batch scoped to the rule's products. The bulk recompute already exists via `pricing:recompute --all --brand=X --category=Y` (Phase 3 Plan 04) — listener translates the event to the matching `--brand` / `--category` flags and kicks off the same pipeline.
3. If `PricingRuleChanged.php` already exists (unlikely but possible via Phase 3 Plan 03+): `MarginChangeApplier` just calls `event(new PricingRuleChanged(...))` after the update. Done.

**Why ship in Plan 3 (not Plan 1):** It's an applier concern; Plan 3 is the applier + analyser plan. The event class itself is trivial (10 lines); the wiring to Phase 3's recompute listener is the integration work.

## §10 — Stale-Feed Detection Cadence

**Recommendation: hourly `competitor:check-stale` command, 48h threshold.**

Rationale:
- Daily is too slow — a 48h stale feed could sit for 23h before the admin sees it.
- Hourly is cheap — single-SQL query: `SELECT id, name FROM competitors WHERE is_active=true AND status='active' AND (last_ingest_at IS NULL OR last_ingest_at < NOW() - INTERVAL 48 HOUR)`. <10ms.
- Notification: once per stale competitor per day (use another Cache::add key with 24h TTL keyed on `(competitor_id, stale_alert_date)`) — prevents a 48h+ outage from emailing ops every hour.

**Scheduler:**
```php
Schedule::command('competitor:check-stale')->hourly()->onOneServer();
```

**Notification channel:** Email via `AlertRecipient` with new `receives_competitor_alerts` boolean column.

**Migration addition (Plan 1):**
```php
Schema::table('alert_recipients', function (Blueprint $table) {
    $table->boolean('receives_competitor_alerts')->default(false)->after('receives_crm_alerts');
});
```

**Seeder update:** Backfill `ops@meetingstore.co.uk` fallback row to `receives_competitor_alerts = true` (same pattern as Phase 2 D-08 + Phase 4 D-12).

**Notification email body:** Competitor name + last_ingest_at + hours-stale + link to `/admin/competitor-ingest-runs?filter[competitor_id]=X`.

## §11 — CSV Retention Prune

**Recommendation: `competitor:csv-prune` daily at 03:40, default 90 days.**

Scope: `storage/app/competitors/archive/` ONLY. Never touches:
- `competitor_prices` rows (COMP-07 mandate).
- `competitor_ingest_runs` rows (keep forever, like Phase 2 `sync_runs`).
- `csv_parse_errors` rows (follow Phase 1 D-05 90d pattern — retention commands grouped).
- `quarantine/` (stays until admin resolves).

**Command pattern:** Mirror Phase 1 Plan 05's `PruneActivityLogCommand`:
```php
class CompetitorCsvPruneCommand extends Command
{
    protected $signature = 'competitor:csv-prune {--days=0}';

    public function handle(Auditor $auditor): int
    {
        $days = (int) ($this->option('days') ?: config('competitor.csv_retention_days', 90));
        if ($days === 0) {
            $this->warn('--days=0 is a no-op safety guard; pass a positive integer');
            return 0;
        }

        $cutoff = now()->subDays($days);
        $path = Storage::disk('local')->path('competitors/archive');
        $deleted = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isFile()) continue;
            if ($file->getMTime() < $cutoff->timestamp) {
                unlink($file->getPathname());
                $deleted++;
            }
        }

        $auditor->record('competitor.csv_pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        return 0;
    }
}
```

**Schedule:** `Schedule::command('competitor:csv-prune')->dailyAt('03:40')->withoutOverlapping(30)->onOneServer();`

## §12 — Plan Breakdown Proposal (recommended 5 plans)

| # | Plan Slug | Scope | Requirements |
|---|-----------|-------|-------------|
| 05-01 | **data-model-admin-crud** | 5 migrations (competitors, competitor_prices, competitor_csv_mappings, competitor_ingest_runs, csv_parse_errors) + 5 Eloquent models + 5 policies + 5 factories + 5 Filament Resources (CompetitorResource, CompetitorPriceResource, CompetitorCsvMappingResource, CompetitorIngestRunResource, CsvParseErrorResource) + `competitor-csv-*` shield permission LIKE patterns in RolePermissionSeeder + additive migrations (products.last_sales_count_90d + last_sales_count_computed_at; alert_recipients.receives_competitor_alerts). | COMP-01 (schema only), COMP-05 (schema only), COMP-07 (schema only) — infrastructure foundation for all others |
| 05-02 | **csv-ingest-pipeline** | `CompetitorWatchCommand` + `IngestCompetitorCsvJob` + `CompetitorCsvChunkJob` + `ColumnHeuristicDetector` + `EncodingDetector` + `DecimalFormatDetector` + `PriceParser` + `CompetitorCsvRowWriter` + `OrphanDetector` (writes `new_product_opportunity` suggestions with D-09 dedup) + `NewProductOpportunityApplier` stub + `CompetitorPriceRecorded` event + `CompetitorCsvIngested` event + `n8n-integration/README.md`. Quarantine flow handler for ambiguous first-ingest. | COMP-01, COMP-02, COMP-03, COMP-04, COMP-05, COMP-06 |
| 05-03 | **margin-analyser-suggestion-producers** | `MarginAnalyser` + `SalesCounterService` + `RecacheSalesCountsJob` + `IncrementSkuSalesCount` listener (subscribes to Phase 1 OrderReceived) + `CompetitorSalesRecacheCommand` + `DispatchMarginAnalyserJob` listener (debounced) + `ComputeMarginSuggestionJob` + `MarginChangeApplier` + `MarginSuggestionCreated` event + `PricingRuleChanged` event (if missing in Phase 3, ship here) + PricingRule observer + config/competitor.php (min_margin_floor_bps, margin_delta_threshold_bps, sales_threshold_90d, consecutive_scrapes_required, beat_by_pennies). | COMP-08, COMP-09 |
| 05-04 | **filament-analysis-page-stale-feed-ui** | `CompetitorAnalysisPage` (SkuPriceTrendChart widget + BiggestMarginDeltasTable widget + per-competitor tabs + stale-feed traffic-light tile) + `CsvIngestIssuesPage` (4 tabs: Quarantine with resolve action / Orphans / Encoding Errors / Value Errors) + `CompetitorCheckStaleCommand` + stale-feed notification Mailable + post-`shield:generate` policy-restore protocol (Pitfall P5-F) + `AlertRecipient.receives_competitor_alerts` Filament form Toggle + hourly schedule in routes/console.php. | COMP-05 (UI), COMP-10, COMP-11 |
| 05-05 | **retention-guardrails-verification** | `CompetitorCsvPruneCommand` + daily schedule + audit logging + Deptrac `Competitor` layer allow-list update `[Foundation, Pricing, Products, Suggestions, Alerting]` + `DeptracCompetitorLayerTest` + `PolicyTemplateIntegrityTest` re-assertion + 05-VERIFICATION.md ship verdict. | COMP-12 |

**Total:** 12 requirements + 9 decisions → 5 plans. Each plan ~25-40 files, ~1-2h duration based on Phase 4 velocity.

## §13 — Deptrac `Competitor` Layer Allow-List

Current state: `Competitor: [Foundation]` in `depfile.yaml` (scaffolded at project init).

**Phase 5 update (Plan 5):**
```yaml
# Phase 5 (Plans 05-01..05-05): Competitor layer cross-domain allow-list.
#   - Foundation: DomainEvent, Auditor, IntegrationLogger, BaseCommand, Context (every Competitor service extends these)
#   - Pricing: PriceCalculator::stripVat (COMP-06) + PricingRule read/update (MarginChangeApplier D-09)
#   - Products: Product model read (SKU match + last_sales_count_90d) (D-08 orphan detection)
#   - Suggestions: MarginChangeApplier + NewProductOpportunityApplier producers (D-06 + D-08)
#   - Alerting: AlertRecipient + AlertDistribution for stale-feed notification (COMP-11)
# Explicitly NOT allowed: CRM, Webhooks, Sync (write path), Feeds.
Competitor: [Foundation, Pricing, Products, Suggestions, Alerting]
```

**`DeptracCompetitorLayerTest` shape (Plan 5, `tests/Architecture/`):**
```php
test('Competitor layer has no Deptrac violations', function () {
    $result = Process::run('php vendor/bin/deptrac analyse --no-progress');
    expect($result->exitCode())->toBe(0);
});

test('Competitor cannot import CRM classes', function () {
    $violator = app_path('Domain/Competitor/Services/__DeptracViolator.php');
    file_put_contents($violator, '<?php namespace App\Domain\Competitor\Services; use App\Domain\CRM\Services\BitrixClient;');
    try {
        $result = Process::run('php vendor/bin/deptrac analyse --no-progress');
        expect($result->exitCode())->not->toBe(0);
    } finally {
        @unlink($violator);
    }
});
```

Mirrors Phase 2 Plan 05 + Phase 4 Plan 05 patterns.

## §14 — n8n Integration README

**File:** `docs/n8n-integration/README.md` (shipped in Plan 2).

**Structure:**
```markdown
# n8n Competitor CSV Integration

## Directory Convention

n8n writes competitor CSVs to this path on the Laravel VPS:
```
storage/app/competitors/incoming/
```

## Filename Convention

Files MUST match this exact pattern:
```
{competitor_slug}_{YYYY-MM-DD}.csv
```

Examples:
- `acme_2026-04-19.csv`
- `avshop_2026-04-19.csv`
- `logitech-distrib_2026-04-19.csv`

**Slug rules:**
- Lowercase a-z, digits 0-9, hyphens and underscores
- Max 64 characters before the date
- The full regex is: `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`

Files that don't match are quarantined to `storage/app/competitors/quarantine/` and surface in the Filament "CSV Ingest Issues" page.

## Atomic Write Protocol

n8n MUST use the `.tmp → rename` pattern:

1. Write the CSV first to `storage/app/competitors/incoming/{slug}_{date}.csv.tmp`.
2. When the write is complete and flushed, rename it to `.csv` (strip `.tmp`).

The watcher scans for `.csv` files only, ignoring `.tmp` — this prevents reading mid-write data. The watcher ALSO enforces `filemtime > 30s` as a secondary guard.

## CSV Format Expectations

**Encoding:** UTF-8 with BOM is safest. Windows-1252 and ISO-8859-1 are also auto-detected, but may produce an encoding-detection warning in the logs.

**Header row:** Required. First row is the headers.

**Expected column names (case + whitespace insensitive):**
- **SKU column** — one of: `sku`, `mpn`, `part_no`, `part number`, `part_number`, `product code`, `product_code`
- **Price column** — one of: `price`, `rrp`, `cost`, `£`, `gbp`, `price_gbp`, `price_ex_vat`, `price_inc_vat`

The ingester picks the first matching column in each category.

**Decimal format:** Both dot-as-decimal (`1234.56`) and comma-as-decimal (`1.234,56`) are supported. Auto-detected from the first 10 rows.

**Currency symbols:** `£`, `GBP`, `€` are stripped automatically. Values are assumed GBP inc-VAT and converted to ex-VAT via the Phase 3 `PriceCalculator::stripVat()` helper.

## Example CSV

```csv
sku,price,brand
LOGI-C920,£89.99,Logitech
POLY-STUDIO-X30,£1299.00,Poly
JBL-310,£129.95,JBL
```

## First-Time Competitor Ingest

When a new competitor slug is seen for the first time, a `competitors` row is auto-created with `status=pending`. An admin must log into Filament (`/admin/competitors`) to:
1. Set the display name
2. Add the website URL (optional)
3. Flip `status=active`

Before `status=active`, subsequent CSVs for that competitor are ingested but don't trigger margin-change suggestions.

## Troubleshooting

| Symptom | Diagnosis |
|---------|-----------|
| File sat in `incoming/` for > 10 minutes, never processed | Check watcher cron: `php artisan schedule:list` — `competitor:watch` should list |
| CSV in `quarantine/` | Check Filament "CSV Ingest Issues" page — ambiguous columns or invalid filename |
| No suggestions fired for a known-drifted competitor | Check `last_sales_count_90d` on the product — must be ≥ 10; or check `competitor_prices` row count — must be ≥ 3 consecutive |
| Feed marked "stale" in Filament | n8n hasn't dropped a file in > 48h; check n8n workflow on the n8n side |
```

Analogous to `docs/wordpress-snippets/README.md` shipped in Phase 4 Plan 05.

---

*Phase: 05-competitor-analysis*
*Research completed: 2026-04-19*
*Target consumers: `gsd-planner` (primary), `/gsd-discuss-phase` (assumption confirmation for A1 + A3)*
