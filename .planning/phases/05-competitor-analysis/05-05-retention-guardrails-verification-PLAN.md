---
phase: 05-competitor-analysis
plan: 05
type: execute
wave: 6
depends_on:
  - "05-04b"
files_modified:
  - app/Domain/Competitor/Console/Commands/CompetitorCsvPruneCommand.php
  - depfile.yaml
  - deptrac.yaml
  - routes/console.php
  - tests/Feature/Competitor/CompetitorCsvPruneCommandTest.php
  - tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php
  - tests/Architecture/DeptracCompetitorLayerTest.php
  - .planning/phases/05-competitor-analysis/05-VERIFICATION.md
autonomous: true
requirements:
  - COMP-12

must_haves:
  truths:
    - "`CompetitorCsvPruneCommand` (signature `competitor:csv-prune {--days=0}`) scheduled daily at 03:40 in routes/console.php with `->withoutOverlapping(30)->onOneServer()`"
    - "Default retention days comes from `config('competitor.csv_retention_days', 90)` when `--days=0` is passed; explicit `--days=N` overrides"
    - "Prune scope is STRICTLY `storage/app/competitors/archive/**` — NEVER touches competitor_prices rows (COMP-07 mandate), NEVER touches competitor_ingest_runs rows, NEVER touches csv_parse_errors rows, NEVER touches quarantine/"
    - "Prune writes `Auditor::record('competitor.csv_pruned', ['deleted_count' => N, 'cutoff_date' => iso, 'days' => N])` on completion"
    - "CompetitorPricesNeverPrunedTest: seed competitor_prices with recorded_at = 5 years ago; run ALL prune commands (competitor:csv-prune, activity-log-prune, integration-events-prune, sync-errors-prune, etc.); assert all competitor_prices rows survive"
    - "Deptrac `Competitor` layer allow-list updated to `[Foundation, Pricing, Products, Suggestions, Alerting]` in depfile.yaml (A4 verified: Alerting needed for StaleFeedNotification + AlertDistribution)"
    - "`DeptracCompetitorLayerTest` in tests/Architecture/ asserts: (a) `php vendor/bin/deptrac analyse --no-progress` exit 0 on the clean codebase, (b) a synthetic violator file importing CRM or Webhooks classes from the Competitor namespace causes exit != 0"
    - "`05-VERIFICATION.md` exists with ship verdict answering: does the phase satisfy all 12 COMP-* requirements? each requirement numbered with evidence (SUMMARY path reference + test file path)"
  artifacts:
    - path: "app/Domain/Competitor/Console/Commands/CompetitorCsvPruneCommand.php"
      provides: "COMP-12 — 90-day default retention on storage/app/competitors/archive/; audited; safety-guarded --days=0 no-op"
      min_lines: 40
    - path: "depfile.yaml"
      provides: "Competitor layer declared with allow-list [Foundation, Pricing, Products, Suggestions, Alerting]"
      contains: "Competitor:"
    - path: "tests/Architecture/DeptracCompetitorLayerTest.php"
      provides: "Negative test — synthetic CRM import from Competitor namespace fails deptrac"
    - path: ".planning/phases/05-competitor-analysis/05-VERIFICATION.md"
      provides: "Per-requirement ship verdict with evidence pointers"
      min_lines: 50
  key_links:
    - from: "app/Domain/Competitor/Console/Commands/CompetitorCsvPruneCommand.php"
      to: "storage/app/competitors/archive/"
      via: "RecursiveIteratorIterator + unlink"
      pattern: "competitors/archive"
    - from: "routes/console.php"
      to: "competitor:csv-prune"
      via: "Schedule::command dailyAt"
      pattern: "competitor:csv-prune"
    - from: "depfile.yaml"
      to: "Deptrac Competitor layer"
      via: "YAML ruleset"
      pattern: "Competitor:\\s*\\[Foundation"
---

<objective>
Guardrails plan — the final shipping gate. Three concerns:
1. **Retention** — `competitor:csv-prune` daily command enforcing 90-day archive retention per COMP-12, with a regression test ensuring competitor_prices rows are NEVER touched (COMP-07 mandate) by any prune pipeline
2. **Deptrac architectural boundary** — `Competitor` layer allow-list locked to `[Foundation, Pricing, Products, Suggestions, Alerting]`; `DeptracCompetitorLayerTest` permanently prevents CRM/Webhooks/Sync cross-imports
3. **Ship verdict** — `05-VERIFICATION.md` per-requirement evidence pointers; this is the Phase 5 done signal

Purpose: After this plan, all 12 COMP-* requirements are provably satisfied + future code changes that violate the Competitor domain boundary will fail CI.

Output: 1 command + 1 Deptrac config update + 1 architectural test + 2 Pest tests + 1 VERIFICATION.md.
</objective>

<execution_context>
@C:/Users/sonny.tanda/.claude/get-shit-done/workflows/execute-plan.md
@C:/Users/sonny.tanda/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-competitor-analysis/05-CONTEXT.md
@.planning/phases/05-competitor-analysis/05-RESEARCH.md
@.planning/phases/05-competitor-analysis/05-01-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-02-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-03-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md

# Phase 2 + Phase 4 prune + Deptrac patterns to mirror
@.planning/phases/02-supplier-sync/02-05-SUMMARY.md
@.planning/phases/04-bitrix24-crm-sync/04-05-SUMMARY.md
@app/Domain/Sync/Console/Commands/PruneSyncErrorsCommand.php
@app/Domain/Foundation/Audit/Console/Commands/PruneActivityLogCommand.php
@tests/Architecture/DeptracCrmLayerTest.php
@depfile.yaml
@deptrac.yaml

@app/Console/Commands/BaseCommand.php
@app/Domain/Foundation/Audit/Services/Auditor.php

<interfaces>
<!-- Existing prune + architecture test patterns to replicate -->

From app/Domain/Sync/Console/Commands/PruneSyncErrorsCommand.php (Phase 2 Plan 05):
```php
class PruneSyncErrorsCommand extends Command
{
    protected $signature = 'sync-errors:prune {--days=0}';
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('sync.prune_days', 30));
        if ($days === 0) { $this->warn('--days=0 is a no-op safety guard'); return 0; }
        // ... prune logic
    }
}
```

From tests/Architecture/DeptracCrmLayerTest.php (Phase 4 Plan 05):
```php
test('CRM layer has no deptrac violations', function () {
    $result = Process::run('php vendor/bin/deptrac analyse --no-progress');
    expect($result->exitCode())->toBe(0);
});
test('CRM cannot import Competitor classes', function () {
    $violator = app_path('Domain/CRM/__DeptracViolator.php');
    file_put_contents($violator, '<?php namespace App\\Domain\\CRM; use App\\Domain\\Competitor\\Models\\Competitor;');
    try { $result = Process::run('...'); expect($result->exitCode())->not->toBe(0); }
    finally { @unlink($violator); }
});
```

Current depfile.yaml (read it first — Phase 1–4 layers already defined):
```yaml
# Layers: Foundation, Products, Pricing, Sync, Webhooks, Suggestions, CRM, Alerting, Competitor
# Allow-list entries define what each layer can depend on
# Phase 5 Plan 01 may have scaffolded `Competitor: [Foundation]` — this plan extends the allow-list
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: CompetitorCsvPruneCommand + schedule entry + COMP-07 regression test</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §11 (full prune command code + scope doc)
    - @app/Domain/Sync/Console/Commands/PruneSyncErrorsCommand.php (most recent prune command pattern — Phase 2 Plan 05)
    - @app/Domain/Foundation/Audit/Console/Commands/PruneActivityLogCommand.php (Phase 1 Plan 05 prune template)
    - @routes/console.php (current schedule entries — append new 03:40 daily)
    - @app/Domain/Foundation/Audit/Services/Auditor.php (record signature)
  </read_first>
  <behavior>
    - Test: `php artisan competitor:csv-prune --days=0` is a no-op warning — returns 0, prints "--days=0 is a no-op safety guard", deletes nothing
    - Test: `competitor:csv-prune --days=90` with 3 files in `storage/app/competitors/archive/2025-01-15/foo.csv` (mtime >90d old) + 2 files in `archive/2026-04-15/bar.csv` (mtime 4 days old) → deletes the 3 old files, preserves 2 recent
    - Test: prune writes Auditor::record with `'competitor.csv_pruned'` tag + context `{deleted_count: 3, cutoff_date: iso, days: 90}`
    - Test: default `--days=0` (no flag) uses `config('competitor.csv_retention_days', 90)` as the floor — asserting with 91-day-old file → deleted
    - Test: prune command NEVER touches files in `storage/app/competitors/incoming/` OR `storage/app/competitors/quarantine/` OR `storage/app/competitors/processing/` (even if mtime > 90d old)
    - Test: `CompetitorPricesNeverPrunedTest` — seed competitor_prices row with recorded_at = 5 years ago, seed CsvParseError row similarly, run ALL available prune commands in sequence (`competitor:csv-prune --days=1`, `activity-log:prune --days=1`, `integration-events:prune --days=1`, `sync-errors:prune --days=1`, any Phase 4 prune) → assert competitor_prices row count unchanged (COMP-07 mandate held)
    - Test: `php artisan schedule:list | grep competitor:csv-prune` shows DailyAt 03:40 entry with withoutOverlapping
  </behavior>
  <action>
**`app/Domain/Competitor/Console/Commands/CompetitorCsvPruneCommand.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Domain\Foundation\Audit\Services\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CompetitorCsvPruneCommand extends Command
{
    protected $signature = 'competitor:csv-prune {--days=0 : Retention in days (0 = use config default)}';
    protected $description = 'Prune competitor CSV archive files older than retention threshold. NEVER touches competitor_prices rows.';

    public function handle(Auditor $auditor): int
    {
        $days = (int) ($this->option('days') ?: config('competitor.csv_retention_days', 90));

        if ($days === 0) {
            $this->warn('--days=0 is a no-op safety guard; pass a positive integer or set COMPETITOR_CSV_RETENTION_DAYS.');
            return 0;
        }

        $cutoff = now()->subDays($days);
        $archivePath = Storage::disk('local')->path('competitors/archive');

        if (! is_dir($archivePath)) {
            $this->info(sprintf('Archive directory does not exist: %s', $archivePath));
            return 0;
        }

        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archivePath));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            // Defensive: skip .gitkeep + anything that doesn't look like a CSV
            if ($file->getFilename() === '.gitkeep') {
                continue;
            }
            if ($file->getMTime() < $cutoff->timestamp) {
                @unlink($file->getPathname());
                $deleted++;
            }
        }

        $auditor->record('competitor.csv_pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
            'archive_path' => $archivePath,
        ]);

        $this->info(sprintf('Pruned %d CSV file(s) older than %d days (cutoff %s).', $deleted, $days, $cutoff->toDateString()));
        return 0;
    }
}
```

Note: this command extends `Command` directly (per Phase 2 Plan 05 precedent — Prune commands don't need BaseCommand's correlation-id threading because they're purely file-system operations).

**Schedule registration** in `routes/console.php` — APPEND:
```php
Schedule::command('competitor:csv-prune')
    ->dailyAt('03:40')
    ->withoutOverlapping(30)
    ->onOneServer();
```

**Tests:**

`tests/Feature/Competitor/CompetitorCsvPruneCommandTest.php`:
- Setup: `Storage::fake('local')` + create `competitors/archive/2025-01-15/old.csv` (mtime 100 days ago via `touch` + `utime`) + `competitors/archive/2026-04-15/fresh.csv` (mtime 4 days ago) + `competitors/incoming/safe.csv` (old mtime but in wrong dir) + `competitors/quarantine/never_touched.csv` (old mtime)
- Test: `artisan('competitor:csv-prune --days=90')` → exit 0 → old.csv deleted, fresh.csv preserved, safe.csv + never_touched.csv preserved
- Test: `artisan('competitor:csv-prune')` (no flag) uses default 90 → same outcome
- Test: `artisan('competitor:csv-prune --days=0')` → exit 0 + warning message + NO files deleted
- Test: Auditor::record called with the 4-key context array

`tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php`:
- Seed: 3 CompetitorPrice rows with recorded_at = now()->subYears(5); 3 CsvParseError rows similarly aged; 3 CompetitorIngestRun rows similarly aged
- Run every prune command available (adapt as needed; exact list depends on 05-04a + 05-04b SUMMARY.md + what Phase 1–4 shipped):
  - `php artisan competitor:csv-prune --days=1`
  - `php artisan activity-log:prune --days=1`
  - `php artisan integration-events:prune --days=1`
  - `php artisan sync-errors:prune --days=1`
  - (Omit any that doesn't exist in this installation — use Artisan::all() to check first)
- Assert: `CompetitorPrice::count() === 3` (unchanged) — COMP-07 mandate held
- Assert: `CompetitorIngestRun::count() === 3` (per RESEARCH Open Question 4: runs kept forever like sync_runs)
- Document: CsvParseError rows are allowed to prune under Phase 1 D-05 90d pattern (they reference deleted archive files); assertion on CsvParseError can be relaxed to "at least the ones referencing unpruned archive files survive" OR (simpler) "CsvParseError count is UNCHANGED by competitor:csv-prune specifically" — pick the simpler assertion.
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/CompetitorCsvPruneCommandTest.php tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php --stop-on-failure && php artisan schedule:list 2>/dev/null | grep -q "competitor:csv-prune"</automated>
  </verify>
  <done>CompetitorCsvPruneCommand ships with --days=0 safety guard + config-default + archive-only scope + Auditor record; 2 Pest tests green; schedule entry registered; COMP-07 regression test proves competitor_prices rows never pruned by any retention command.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Deptrac Competitor layer allow-list + architectural test</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §13 (Deptrac allow-list + test shape)
    - @depfile.yaml (current ruleset — most likely has `Competitor: [Foundation]` scaffolded)
    - @deptrac.yaml (alternative config if project uses .yaml variant)
    - @tests/Architecture/DeptracCrmLayerTest.php (Phase 4 Plan 05 test shape — exit-code only per P2-E lesson)
    - @.planning/phases/04-bitrix24-crm-sync/04-05-SUMMARY.md (most recent Deptrac layer addition)
    - @.planning/phases/02-supplier-sync/02-05-SUMMARY.md (Plan 02-05 — exit-code-only assertion learned)
  </read_first>
  <behavior>
    - Test: `php vendor/bin/deptrac analyse --no-progress` on the clean codebase (after Plans 05-01..05-04b shipped) exits 0 — zero violations
    - Test: inject a synthetic violator file `app/Domain/Competitor/__DeptracViolator.php` with `use App\Domain\CRM\Services\BitrixClient;` → deptrac exit code != 0 → file deleted via finally block
    - Test: inject synthetic violator with `use App\Domain\Webhooks\Services\WebhookIngester;` → deptrac exit code != 0 (Webhooks not in allow-list)
    - Test: verify allow-list entries in depfile.yaml: grep for `Competitor:` followed by brackets containing `Foundation` + `Pricing` + `Products` + `Suggestions` + `Alerting` — all 5 present
  </behavior>
  <action>
**Update `depfile.yaml`** (the repo's primary Deptrac config — confirm name first; fallback to `deptrac.yaml` if `depfile.yaml` absent):

Locate the ruleset section — most likely under `deptrac.ruleset:` top-level key. Find the `Competitor:` entry (scaffolded in Plan 01 or pre-Phase-5). Update the list to:
```yaml
Competitor:
  - Foundation
  - Pricing
  - Products
  - Suggestions
  - Alerting
```

Add an explanatory comment above the entry:
```yaml
# Phase 5 (Plans 05-01..05-05): Competitor layer cross-domain allow-list.
#   - Foundation: DomainEvent, Auditor, IntegrationLogger, BaseCommand, Context (every Competitor service extends these)
#   - Pricing: PriceCalculator::stripVat (COMP-06) + PricingRule read/update (MarginChangeApplier per 05-03)
#   - Products: Product model read (SKU match + last_sales_count_90d) (D-08 orphan detection)
#   - Suggestions: MarginChangeApplier + NewProductOpportunityApplier producers (D-06 + D-08)
#   - Alerting: AlertRecipient + AlertDistribution for stale-feed notification (COMP-11)
# Explicitly NOT allowed: CRM, Webhooks, Sync (write path), Feeds.
Competitor:
  - Foundation
  - Pricing
  - Products
  - Suggestions
  - Alerting
```

**Create `tests/Architecture/DeptracCompetitorLayerTest.php`** — mirrors Phase 4 DeptracCrmLayerTest exit-code-only shape (per Phase 2 Plan 05 P2-E lesson — stdout grep unreliable on Windows Symfony\Process):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

test('Competitor layer has no deptrac violations', function () {
    $result = Process::run('php vendor/bin/deptrac analyse --no-progress --no-interaction');

    // Exit code 0 = no violations; 1 = violations found; 2+ = deptrac internal error
    expect($result->exitCode())->toBe(0);
});

test('Competitor cannot import CRM classes', function () {
    $violator = app_path('Domain/Competitor/__DeptracViolatorCrm.php');
    file_put_contents($violator, <<<'PHP'
<?php

namespace App\Domain\Competitor;

use App\Domain\CRM\Services\BitrixClient;

class __DeptracViolatorCrm
{
    public function __construct(public BitrixClient $client) {}
}
PHP);

    try {
        $result = Process::run('php vendor/bin/deptrac analyse --no-progress --no-interaction');
        expect($result->exitCode())->not->toBe(0); // violation detected
    } finally {
        @unlink($violator);
    }
});

test('Competitor cannot import Webhooks classes', function () {
    $violator = app_path('Domain/Competitor/__DeptracViolatorWebhooks.php');
    file_put_contents($violator, <<<'PHP'
<?php

namespace App\Domain\Competitor;

use App\Domain\Webhooks\Controllers\WooWebhookController;

class __DeptracViolatorWebhooks
{
    public function __construct(public WooWebhookController $controller) {}
}
PHP);

    try {
        $result = Process::run('php vendor/bin/deptrac analyse --no-progress --no-interaction');
        expect($result->exitCode())->not->toBe(0);
    } finally {
        @unlink($violator);
    }
});
```

If the import path for WebhookController differs in the actual codebase (e.g., `App\Http\Controllers\Webhooks\WooWebhookController`), adjust accordingly — read the actual namespace with a preliminary grep. The intent is to pick ANY class from the `Webhooks` layer that wouldn't normally be imported by Competitor.
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Architecture/DeptracCompetitorLayerTest.php --stop-on-failure && grep -A5 "Competitor:" depfile.yaml | grep -q "Alerting"</automated>
  </verify>
  <done>Deptrac `Competitor` layer allow-list locks to 5 deps (Foundation, Pricing, Products, Suggestions, Alerting); DeptracCompetitorLayerTest has 3 tests — clean-codebase passes, 2 synthetic violators each fail deptrac with non-zero exit; grep confirms depfile.yaml contains Alerting in the Competitor allow-list.</done>
</task>

<task type="auto">
  <name>Task 3: 05-VERIFICATION.md ship verdict</name>
  <read_first>
    - @.planning/phases/04-bitrix24-crm-sync/04-VERIFICATION.md (most recent VERIFICATION shape to mirror)
    - @.planning/phases/02-supplier-sync/02-VERIFICATION.md (Phase 2 VERIFICATION as secondary reference)
    - @.planning/REQUIREMENTS.md §COMP-01..COMP-12 (each acceptance criterion re-confirmed)
    - @.planning/phases/05-competitor-analysis/05-01-SUMMARY.md
    - @.planning/phases/05-competitor-analysis/05-02-SUMMARY.md
    - @.planning/phases/05-competitor-analysis/05-03-SUMMARY.md
    - @.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md
@.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md
  </read_first>
  <acceptance_criteria>
    - `.planning/phases/05-competitor-analysis/05-VERIFICATION.md` exists
    - Contains one section per COMP-01..COMP-12 with: requirement ID + acceptance criterion + evidence (file/SUMMARY/test path)
    - Contains a top-level "Ship Verdict: ✅ APPROVED" or "⚠️ BLOCKED" paragraph
    - Contains a section listing the 3 new scheduled commands (competitor:watch, competitor:sales-recache, competitor:check-stale, competitor:csv-prune — 4 total) + their schedules
    - Contains a section listing the 5 Deptrac allow-list entries with the rationale
    - Contains a section listing the 2 new Suggestion kinds registered (margin_change, new_product_opportunity) + their appliers
    - Contains a section listing open v2 follow-ups (MAP monitoring, MySQL 8 partitioning at 10M rows, fuzzy MPN matching, auto-apply, multi-currency — per CONTEXT Deferred Ideas)
    - Contains a section confirming NO competitor_prices row is pruned by any retention command (COMP-07 mandate regression test location)
  </acceptance_criteria>
  <action>
Create `.planning/phases/05-competitor-analysis/05-VERIFICATION.md`:

```markdown
# Phase 5 (Competitor Analysis) — Ship Verification

**Verified:** <YYYY-MM-DD at execution time>
**Verdict:** ✅ APPROVED — all 12 COMP requirements satisfied, guardrails green.

<hr />

## Per-Requirement Evidence

### COMP-01 — CSVs in `storage/app/competitors/` picked up by scheduled watcher
- **Evidence:** `app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php` + `routes/console.php` Schedule::command('competitor:watch')->everyFiveMinutes()
- **SUMMARY:** 05-02
- **Test:** `tests/Feature/Competitor/CompetitorWatchCommandTest.php` — 4 scenarios pass

### COMP-02 — Column auto-detection for sku/mpn + price
- **Evidence:** `app/Domain/Competitor/Services/ColumnHeuristicDetector.php`
- **SUMMARY:** 05-02
- **Test:** `tests/Feature/Competitor/ColumnHeuristicDetectorTest.php`

### COMP-03 — UTF-8 BOM, Windows-1252, European decimals no silent failure
- **Evidence:** EncodingDetector + DecimalFormatDetector + PriceParser
- **SUMMARY:** 05-02
- **Tests:** EncodingDetectorTest, DecimalFormatDetectorTest, PriceParserTest (+ fixtures utf8_bom.csv, windows1252.csv, european_decimal.csv)

### COMP-04 — Atomic .tmp → rename + mtime>30s gate
- **Evidence:** `CompetitorWatchCommand::perform` mtime check + filename regex + flock probe on Windows
- **SUMMARY:** 05-02
- **Test:** `CompetitorWatchCommandTest` fresh-mtime case

### COMP-05 — Per-row parse errors logged + surfaced in Filament
- **Evidence:** `csv_parse_errors` table + `CsvParseErrorResource` + `CsvIngestIssuesPage` 4-tab layout
- **SUMMARY:** 05-01 (schema) + 05-02 (writes) + 05-04a (Resource) + 05-04b (Page)
- **Tests:** `CsvIngestIssuesPageResolveActionTest`, `IngestCompetitorCsvJobTest` (quarantine path)

### COMP-06 — Currency strip + VAT removal via PriceCalculator::stripVat
- **Evidence:** `CompetitorCsvRowWriter` + `MarginAnalyser` both call `App\Domain\Pricing\Services\PriceCalculator::stripVat` — zero duplicate VAT math
- **SUMMARY:** 05-02 + 05-03
- **Test:** `tests/Feature/Competitor/StripVatReuseTest.php` grep-based guardrail

### COMP-07 — Every competitor price persisted, history NEVER truncated
- **Evidence:** `competitor_prices` table + unique index (competitor_id, sku, recorded_at) + NO truncate logic in any ingest path + prune scope scoped to `archive/` only
- **SUMMARY:** 05-01 (schema) + 05-05 (prune scope)
- **Test:** `tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php` — 5-year-old rows survive all prune commands

### COMP-08 — MarginAnalyser with 8% delta + 3 scrapes + 10 sales/90d thresholds
- **Evidence:** `MarginAnalyser` + `ComputeMarginSuggestionJob` + `SalesCounterService` + `config/competitor.php` all 3 thresholds
- **SUMMARY:** 05-03
- **Tests:** `MarginAnalyserTest`, `ComputeMarginSuggestionJobTest`, `DispatchMarginAnalyserJobTest`, `DebounceKeyTest`, `MinMarginFloorGuardTest`

### COMP-09 — Margin-change suggestions → `suggestions` table; approve → PricingRule update + audit
- **Evidence:** `MarginChangeApplier` registered for kind=margin_change + `PricingRuleChanged` event (shipped in 05-03 per A1 backport) + Auditor::record on apply
- **SUMMARY:** 05-03
- **Tests:** `MarginChangeApplierTest` + `MarginChangeSuggestionApproveActionTest` (Filament E2E)

### COMP-10 — Filament Competitor Analysis page — trend + biggest deltas + per-competitor
- **Evidence:** `CompetitorAnalysisPage` + `SkuPriceTrendChart` + `BiggestMarginDeltasTable` + per-competitor Tabs
- **SUMMARY:** 05-04b
- **Human verify:** checkpoint cleared in 05-04b Task 3

### COMP-11 — Stale-feed detection warns admin when competitor >48h stale
- **Evidence:** `CompetitorCheckStaleCommand` hourly schedule + `StaleFeedNotification` + `AlertRecipient.receives_competitor_alerts` column + 24h dedup Cache::add key
- **SUMMARY:** 05-04b
- **Tests:** `CompetitorCheckStaleCommandTest`, `StaleFeedNotificationTest`

### COMP-12 — Competitor CSV files pruned after 90 days (configurable)
- **Evidence:** `CompetitorCsvPruneCommand` + `routes/console.php` daily 03:40 + `config('competitor.csv_retention_days', 90)` + Auditor record
- **SUMMARY:** 05-05
- **Tests:** `CompetitorCsvPruneCommandTest`, `CompetitorPricesNeverPrunedTest`

<hr />

## Scheduled Commands (4 new)

| Command | Schedule | Purpose |
|---------|----------|---------|
| `competitor:watch` | every 5 minutes | Detects CSV drops; moves to processing/; dispatches IngestCompetitorCsvJob |
| `competitor:sales-recache` | daily 02:00 | Authoritative 90-day sales count recompute (sync-bulk queue) |
| `competitor:check-stale` | hourly | Stale-feed (>48h) detection + notification dispatch |
| `competitor:csv-prune` | daily 03:40 | 90-day CSV archive retention (never touches competitor_prices) |

<hr />

## Deptrac Allow-List

| Dep | Rationale |
|-----|-----------|
| Foundation | DomainEvent, Auditor, IntegrationLogger, BaseCommand, Context |
| Pricing | PriceCalculator::stripVat (COMP-06) + PricingRule read/update (MarginChangeApplier) |
| Products | Product model read (SKU match + last_sales_count_90d orphan detection) |
| Suggestions | MarginChangeApplier + NewProductOpportunityApplier producers |
| Alerting | AlertRecipient + AlertDistribution for StaleFeedNotification |

**Explicitly NOT allowed:** CRM, Webhooks, Sync (write path), Feeds. Enforced via `tests/Architecture/DeptracCompetitorLayerTest.php`.

<hr />

## SuggestionApplier Kinds Registered (2 new)

| Kind | Applier | Phase 5 state |
|------|---------|---------------|
| `margin_change` | `MarginChangeApplier` | Fully operational — mutates PricingRule + fires PricingRuleChanged |
| `new_product_opportunity` | `NewProductOpportunityApplier` | Stub (Phase 6 will replace with supplier-request-list integration) |

<hr />

## COMP-07 Preservation — Regression Test

Ship-gate test: `tests/Feature/Competitor/CompetitorPricesNeverPrunedTest.php`. Seeds competitor_prices + competitor_ingest_runs rows with `recorded_at` 5 years ago; runs ALL available prune commands; asserts row counts unchanged. This is a permanent guardrail — any future phase that introduces a competitor_prices prune MUST update this test to prove COMP-07 is preserved under new constraints (OR COMP-07 must be revised with a new product decision).

<hr />

## Deferred (v2 / Future Phases)

- **MAP monitoring** (research C.3) — pending ops confirmation of MAP-protected brand coverage
- **MySQL 8 partitioning for competitor_prices** — revisit at 10M+ rows (currently projected 3.6M/year)
- **Fuzzy MPN matching + confidence score** — research C.2 differentiator; v1 ships exact-SKU match
- **Price-change notifications (Slack/email)** — defer to Phase 7 notification centre consolidation
- **Auto-apply margin suggestions** — violates suggestions-first project constraint; Phase 10 AI-agent territory
- **Real-time competitor price feeds** — research C.4 anti-feature (daily CSV is the data contract)
- **Multi-currency competitor prices** — v1 assumes GBP inc-VAT; schema forward-compatible for v2

<hr />

## Sign-off

All 12 COMP requirements satisfied with evidence pointers above. 4 scheduled commands wired. 2 suggestion kinds producing. Deptrac boundary locked. Policy template integrity guaranteed. COMP-07 preservation permanently tested.

**Phase 5 ships.** 🟢
```

Write to `.planning/phases/05-competitor-analysis/05-VERIFICATION.md`. Fill in the date at execution time.
  </action>
  <verify>
    <automated>test -f .planning/phases/05-competitor-analysis/05-VERIFICATION.md && grep -q "COMP-12" .planning/phases/05-competitor-analysis/05-VERIFICATION.md && grep -q "Ship Verdict\|Phase 5 ships" .planning/phases/05-competitor-analysis/05-VERIFICATION.md && grep -c "COMP-" .planning/phases/05-competitor-analysis/05-VERIFICATION.md | awk '$1 >= 12 { exit 0 } { exit 1 }'</automated>
  </verify>
  <done>05-VERIFICATION.md exists with 12 per-requirement evidence entries + ship verdict + schedule table + Deptrac allow-list + applier kind table + COMP-07 regression pointer + deferred list.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Scheduler → CompetitorCsvPruneCommand | System-triggered file deletion; no user input |
| Deptrac test synthetic violator | Test-injected file; try/finally cleanup |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-05-01 | Tampering | Retention command scope bypass | mitigate | Path constant resolved via `Storage::disk('local')->path('competitors/archive')`; never reads from config or user-supplied path; CompetitorPricesNeverPrunedTest is the permanent regression guard. |
| T-05-05-02 | Denial of Service | Retention command runaway delete | accept | Scope is the archive dir only; no user-supplied `--path` flag. Worst case: deletes archive contents → operationally recoverable (CSVs come from n8n re-runs). |
| T-05-05-03 | Tampering | Deptrac synthetic violator file persistence | mitigate | Test uses try/finally to ensure unlink runs even on assertion failure; CI pipeline reruns unlink as a safety net (`rm -f app/Domain/Competitor/__DeptracViolator*.php`). |
| T-05-05-04 | Elevation of Privilege | Competitor domain importing restricted layers | mitigate | Deptrac ruleset + architectural test — CI fails on cross-domain imports to CRM, Webhooks, Sync, Feeds. |
</threat_model>

<verification>
- `php vendor/bin/pest tests/Feature/Competitor/ tests/Architecture/` all green
- `php vendor/bin/deptrac analyse --no-progress` exit 0 on the clean codebase
- `php artisan schedule:list` shows all 4 Phase 5 entries
- `05-VERIFICATION.md` exists and enumerates all 12 COMP-* requirements
- `grep -A6 "Competitor:" depfile.yaml` shows the 5-entry allow-list
- CompetitorPricesNeverPrunedTest passes — permanent COMP-07 regression guard installed
</verification>

<success_criteria>
- CompetitorCsvPruneCommand scheduled + tested + never touches competitor_prices
- Deptrac Competitor layer locked to [Foundation, Pricing, Products, Suggestions, Alerting]
- DeptracCompetitorLayerTest has 3 passing tests (clean codebase + 2 violator injections)
- 05-VERIFICATION.md documents ship verdict per-requirement
- All Phase 1-4 test suites still green (no regressions)
- PolicyTemplateIntegrityTest passes with updated floor
- `php artisan schedule:list` shows 4 Phase 5 schedule entries
</success_criteria>

<output>
Create `.planning/phases/05-competitor-analysis/05-05-SUMMARY.md` documenting:
- Prune command actual delete counts during Pest test runs (for future capacity planning)
- Any Deptrac ruleset path quirks (depfile.yaml vs deptrac.yaml) discovered during the update
- Whether `DeptracCompetitorLayerTest` exit-code-only approach sufficed (or if stdout parsing was needed on the current deptrac version)
- Final ROADMAP.md update: all Phase 5 plan checkboxes ticked, Phase 5 marked complete
- Open handoff items for Phase 6 (NewProductOpportunityApplier real implementation; orphan suggestion → supplier-request-list integration)
</output>
