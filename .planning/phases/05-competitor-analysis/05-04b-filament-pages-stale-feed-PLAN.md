---
phase: 05-competitor-analysis
plan: 04b
type: execute
wave: 5
depends_on:
  - "05-04a"
files_modified:
  - app/Domain/Competitor/Filament/Pages/CompetitorAnalysisPage.php
  - app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php
  - app/Domain/Competitor/Filament/Widgets/SkuPriceTrendChart.php
  - app/Domain/Competitor/Filament/Widgets/BiggestMarginDeltasTable.php
  - app/Domain/Competitor/Filament/Widgets/StaleFeedTrafficLight.php
  - app/Domain/Competitor/Console/Commands/CompetitorCheckStaleCommand.php
  - app/Domain/Competitor/Notifications/StaleFeedNotification.php
  - database/seeders/CompetitorDemoSeeder.php
  - resources/views/filament/pages/competitor-analysis.blade.php
  - resources/views/filament/pages/csv-ingest-issues.blade.php
  - resources/views/filament/widgets/stale-feed-traffic-light.blade.php
  - routes/console.php
  - tests/Feature/Competitor/CsvIngestIssuesPageResolveActionTest.php
  - tests/Feature/Competitor/CompetitorCheckStaleCommandTest.php
  - tests/Feature/Competitor/StaleFeedNotificationTest.php
  - tests/Feature/Competitor/BiggestMarginDeltasTableTest.php
autonomous: false
requirements:
  - COMP-10
  - COMP-11

must_haves:
  truths:
    - "`/admin/competitor-analysis` CompetitorAnalysisPage renders for admin + pricing_manager + sales; read_only denied via canAccess()"
    - "Page composes 3 widgets: StaleFeedTrafficLight (header — Fresh/Stale/Missing counts using `config('competitor.stale_feed_hours', 48)`), SkuPriceTrendChart (footer — per-SKU line chart with 7/30/90/365-day filter), BiggestMarginDeltasTable (footer — top 50 by ABS(delta) DESC)"
    - "BiggestMarginDeltasTable query adds `WHERE products.sell_price_pennies IS NOT NULL` (W4 null-safety); products missing a recomputed sell_price render as 'not yet analysed' in the UI (stale Phase 3 recompute is visible, not a SQL error)"
    - "SkuPriceTrendChart filter `public ?string $filter = '30'`; `getFilters` returns ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year']; switching filter via Livewire rebuilds chart datasets without full page reload"
    - "`/admin/csv-ingest-issues` CsvIngestIssuesPage renders with 4 tabs (Quarantine, Orphans, Encoding Errors, Value Errors); Quarantine tab has Resolve action opening a modal with first-10-rows preview + SKU/Price column pickers + decimal_format radio"
    - "Resolve action is `->authorize('update', CompetitorCsvMapping::class)` gated (D-04 — pricing_manager can resolve)"
    - "Resolve submit: creates or updates CompetitorCsvMapping row for the competitor, moves file from `storage/app/competitors/quarantine/{fname}` to `storage/app/competitors/incoming/{fname}` via `rename`, dispatches IngestCompetitorCsvJob on `competitor-csv` queue, marks the csv_parse_errors row resolved_at=now()"
    - "Orphans tab: table of `suggestions` where kind='new_product_opportunity' and status='pending'; each row links to the main SuggestionResource inbox for approval"
    - "CompetitorCheckStaleCommand (signature `competitor:check-stale`, extends BaseCommand) scheduled HOURLY in routes/console.php with `->withoutOverlapping(10)->onOneServer()`; queries `Competitor::where('status','active')->where('is_active', true)->where(fn => last_ingest_at IS NULL OR last_ingest_at < NOW() - INTERVAL config_hours HOUR)`"
    - "StaleFeedNotification dispatches via `Notification::send(AlertRecipient::where('receives_competitor_alerts', true)->where('is_active', true)->get(), new StaleFeedNotification(...))` — uses the column toggle from 05-04a"
    - "24h dedup: `Cache::add('competitor.stale_alert.{id}.{YYYY-MM-DD}', true, 24h)` — second invocation same day returns false → notification skipped"
    - "CompetitorDemoSeeder exists under database/seeders/ with idempotent firstOrCreate for: 3 demo Competitor rows (1 fresh <48h, 1 stale >48h, 1 missing last_ingest_at=null), 20+ CompetitorPrice rows across 30 days for a known demo SKU, 1 margin_change Suggestion with D-07 evidence + payload, 1 new_product_opportunity Suggestion with supporting_competitors=2, 1 csv_parse_error(issue_type=ambiguous_mapping) + matching CSV file written to `storage/app/competitors/quarantine/demo_2026-04-21.csv`"
    - "Human-verify checkpoint runs the operator through a 12-point walkthrough driven by CompetitorDemoSeeder output — operator does NOT manually seed fixtures"
  artifacts:
    - path: "app/Domain/Competitor/Filament/Pages/CompetitorAnalysisPage.php"
      provides: "COMP-10 page — trend chart + biggest deltas + stale-feed tile + per-competitor tabs"
      min_lines: 80
    - path: "app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php"
      provides: "COMP-05 UI — 4 tabs; Quarantine resolve modal re-dispatches ingest job"
      min_lines: 100
    - path: "app/Domain/Competitor/Filament/Widgets/SkuPriceTrendChart.php"
      provides: "Per-SKU Chart.js line chart with time-window toggles"
      min_lines: 60
    - path: "app/Domain/Competitor/Filament/Widgets/BiggestMarginDeltasTable.php"
      provides: "Top-50 sortable table; W4 null-safety on products.sell_price_pennies"
    - path: "app/Domain/Competitor/Filament/Widgets/StaleFeedTrafficLight.php"
      provides: "Fresh/Stale/Missing StatsOverviewWidget using config('competitor.stale_feed_hours')"
    - path: "app/Domain/Competitor/Console/Commands/CompetitorCheckStaleCommand.php"
      provides: "COMP-11 — hourly stale-feed detector with 24h dedup + AlertRecipient routing"
      min_lines: 50
    - path: "app/Domain/Competitor/Notifications/StaleFeedNotification.php"
      provides: "Mailable — subject, body, action URL to /admin/competitor-ingest-runs filtered by competitor"
    - path: "database/seeders/CompetitorDemoSeeder.php"
      provides: "Idempotent demo fixture generator for human-verify walkthrough; replaces manual seeding burden"
      min_lines: 70
  key_links:
    - from: "app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php"
      to: "app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php"
      via: "Resolve action re-dispatches IngestCompetitorCsvJob"
      pattern: "IngestCompetitorCsvJob::dispatch"
    - from: "app/Domain/Competitor/Console/Commands/CompetitorCheckStaleCommand.php"
      to: "app/Domain/Alerting/Models/AlertRecipient.php"
      via: "where('receives_competitor_alerts', true) scope"
      pattern: "receives_competitor_alerts"
    - from: "routes/console.php"
      to: "competitor:check-stale"
      via: "Schedule::command hourly"
      pattern: "competitor:check-stale"
    - from: "app/Domain/Competitor/Filament/Widgets/BiggestMarginDeltasTable.php"
      to: "products.sell_price_pennies"
      via: "JOIN + WHERE sell_price_pennies IS NOT NULL"
      pattern: "sell_price_pennies IS NOT NULL"
---

<objective>
Second half of Phase 5's UI: the analytical custom Pages (CompetitorAnalysisPage with 3 widgets + per-competitor tabs, CsvIngestIssuesPage with 4 tabs + Quarantine resolve flow) + the hourly stale-feed detection command and its Mailable notification + a CompetitorDemoSeeder that makes the human-verify checkpoint repeatable with one artisan command.

Purpose: Ops can now SEE competitor data — trend charts, biggest-delta catalogue view, stale-feed traffic light, per-competitor tabs — plus the Quarantine resolve flow (the ONLY manual config surface in the whole pipeline per D-04), and the automated stale-feed warning via the AlertRecipient column shipped in 05-04a.

Output: 2 Pages + 3 Widgets + 1 Command + 1 Notification + CompetitorDemoSeeder + 3 Blade views + 4 Pest tests + 1 human-verify checkpoint. All lands on top of 05-04a's shield-generated permissions + SuggestionResource extensions.
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

# Filament Page + Widget patterns to mirror
@.planning/phases/02-supplier-sync/02-04-SUMMARY.md
@app/Domain/Sync/Filament/Pages/SupplierSyncStatusPage.php
@app/Domain/Sync/Filament/Widgets/

# 05-02 ingest job (Resolve action re-dispatches this)
@app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php
@app/Domain/Competitor/Models/CompetitorCsvMapping.php

# 05-03 margin analyser contract (widgets read evidence JSON)
@app/Domain/Competitor/Services/MarginAnalyser.php

# Alerting patterns
@app/Domain/Alerting/Services/AlertDistribution.php
@app/Domain/Alerting/Models/AlertRecipient.php

<interfaces>
<!-- 05-04a + prior contracts this plan consumes -->

From Filament 3.3 ChartWidget (built-in):
```php
abstract class ChartWidget extends Widget
{
    protected function getData(): array; // ['datasets' => [...], 'labels' => [...]]
    protected function getType(): string; // 'line' | 'bar' | ...
    protected function getFilters(): ?array; // key => label pairs for time-range toggles
    public ?string $filter = '30';
}
```

From app/Domain/Alerting/Services/AlertDistribution.php (Phase 4 Plan 03):
```php
class AlertDistribution extends Notification
{
    public function __construct(public readonly string $onlyReceiving) {}
    // Phase 5 uses direct Notification::send against AlertRecipient query since
    // stale-feed notification needs model context (competitor) in the mail body.
}
```

From 05-04a (FROZEN): 
- `AlertRecipient.receives_competitor_alerts` column exists + toggle form field persists.
- Shield permissions for Competitor resources generated; RolePermissionSeeder extended.
- SuggestionResource Approve actions wired for margin_change + new_product_opportunity kinds.

From 05-02 (FROZEN):
- `IngestCompetitorCsvJob::dispatch($processingPath, $competitorId)->onQueue('competitor-csv')` — re-dispatched by Resolve action.
- `storage/app/competitors/{incoming,quarantine,processing,archive}/` directories exist.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: CompetitorAnalysisPage + 3 widgets + CsvIngestIssuesPage resolve flow</name>
  <files>
    app/Domain/Competitor/Filament/Pages/CompetitorAnalysisPage.php,
    app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php,
    app/Domain/Competitor/Filament/Widgets/SkuPriceTrendChart.php,
    app/Domain/Competitor/Filament/Widgets/BiggestMarginDeltasTable.php,
    app/Domain/Competitor/Filament/Widgets/StaleFeedTrafficLight.php,
    resources/views/filament/pages/competitor-analysis.blade.php,
    resources/views/filament/pages/csv-ingest-issues.blade.php,
    resources/views/filament/widgets/stale-feed-traffic-light.blade.php,
    tests/Feature/Competitor/CsvIngestIssuesPageResolveActionTest.php,
    tests/Feature/Competitor/BiggestMarginDeltasTableTest.php
  </files>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §4 (trend chart + biggest-delta query) §6 (CsvIngestIssuesPage 4-tab structure + resolve form)
    - @app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php (from 05-02 — resolve form re-dispatches this)
    - @app/Domain/Competitor/Models/CompetitorCsvMapping.php (FORMAT_DOT / FORMAT_COMMA constants)
    - @resources/views/filament/pages/ (existing custom Blade views for reference)
    - @app/Domain/Sync/Filament/ (Phase 2 custom Page reference)
  </read_first>
  <acceptance_criteria>
    - CompetitorAnalysisPage renders at /admin/competitor-analysis for admin + pricing_manager + sales; read_only denied via canAccess()
    - 3 widgets compose the page; StaleFeedTrafficLight in header widgets, SkuPriceTrendChart + BiggestMarginDeltasTable in footer widgets
    - SkuPriceTrendChart filter toggles (7/30/90/365) rebuild chart via Livewire without page reload; empty data returns ['datasets' => [], 'labels' => []] (not null)
    - BiggestMarginDeltasTable query includes `WHERE products.sell_price_pennies IS NOT NULL` (W4); paginated 50 rows, ORDER BY ABS(delta) DESC
    - StaleFeedTrafficLight Stats show Fresh / Stale / Missing counts using config('competitor.stale_feed_hours', 48) as threshold
    - CsvIngestIssuesPage at /admin/csv-ingest-issues has 4 tabs: Quarantine, Orphans, Encoding Errors, Value Errors
    - Quarantine Resolve action: modal opens with first-10-rows preview, Select(sku_column_index), Select(price_column_index), Radio(decimal_format) — submit creates CompetitorCsvMapping, moves file from quarantine/ to incoming/, dispatches IngestCompetitorCsvJob on competitor-csv queue, sets csv_parse_errors.resolved_at=now()
    - Pest: Livewire Resolve action end-to-end — Queue::assertPushed IngestCompetitorCsvJob + CompetitorCsvMapping row exists + file moved + csv_parse_errors.resolved_at not null
    - Pest: BiggestMarginDeltasTable query excludes products where sell_price_pennies IS NULL (seed 1 such product + 1 with sell_price set → widget shows only the non-null row)
  </acceptance_criteria>
  <action>
**`CompetitorAnalysisPage.php`** (`app/Domain/Competitor/Filament/Pages/`):
```php
namespace App\Domain\Competitor\Filament\Pages;

use Filament\Pages\Page;
use App\Domain\Competitor\Filament\Widgets\{SkuPriceTrendChart, BiggestMarginDeltasTable, StaleFeedTrafficLight};
use App\Domain\Competitor\Models\Competitor;

class CompetitorAnalysisPage extends Page
{
    protected static ?string $navigationGroup = 'Competitor Intelligence';
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static string $view = 'filament.pages.competitor-analysis';
    protected static ?string $title = 'Competitor Analysis';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', \App\Domain\Competitor\Models\CompetitorPrice::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [StaleFeedTrafficLight::class];
    }

    protected function getFooterWidgets(): array
    {
        return [SkuPriceTrendChart::class, BiggestMarginDeltasTable::class];
    }
}
```

**`SkuPriceTrendChart.php`** — Filament ChartWidget:
- `protected function getFilters(): array { return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year']; }`
- `public ?string $filter = '30';`
- `protected function getType(): string { return 'line'; }`
- `protected function getData(): array`:
  - Parse `(int) $this->filter` as days; fallback 30 if non-numeric
  - Build labels = range of dates from now-Ndays to today
  - For each active Competitor: fetch `CompetitorPrice::where('competitor_id', $c->id)->where('sku', $selectedSku)->where('recorded_at', '>=', now()->subDays($days))->orderBy('recorded_at')->pluck('price_pennies_ex_vat', 'recorded_at')`
  - Produce a dataset per competitor + our sell_price_pennies overlay (borderColor #10b981, borderDash [5,5])
  - Empty data → `return ['datasets' => [], 'labels' => []];`
- Public property `public ?string $sku = null;` — parent page sets via `$this->sku = request('sku')` on mount

**`BiggestMarginDeltasTable.php`** — Filament TableWidget:
```php
protected function getTableQuery(): Builder
{
    return CompetitorPrice::query()
        ->selectRaw('competitor_prices.*, products.sell_price_pennies, ABS(products.sell_price_pennies - competitor_prices.price_pennies_ex_vat) as delta_pennies, competitors.name as competitor_name')
        ->join('products', 'products.sku', '=', 'competitor_prices.sku')
        ->join('competitors', 'competitors.id', '=', 'competitor_prices.competitor_id')
        ->whereNotNull('products.sell_price_pennies')   // W4 null-safety: skip products where Phase 3 recompute hasn't populated sell_price yet
        ->whereIn('competitor_prices.id', function ($q) {
            $q->selectRaw('MAX(id)')
              ->from('competitor_prices as cp')
              ->whereColumn('cp.competitor_id', 'competitor_prices.competitor_id')
              ->whereColumn('cp.sku', 'competitor_prices.sku')
              ->groupBy('cp.competitor_id', 'cp.sku');
        })
        ->orderByRaw('delta_pennies DESC')
        ->limit(50);
}
```
- Columns: sku (searchable), competitor_name, products.sell_price_pennies (money GBP), price_pennies_ex_vat (money GBP), delta_pennies (money GBP, color='danger' when `$record->price_pennies_ex_vat < $record->sell_price_pennies` = we're more expensive), recorded_at
- Help text below table: "Products without a recomputed Phase 3 sell_price appear as 'not yet analysed' and are omitted from this view until PricingRule resolution runs. Delta shown is |our_sell − competitor_ex_vat|."

**`StaleFeedTrafficLight.php`** — StatsOverviewWidget:
```php
protected function getStats(): array
{
    $threshold = (int) config('competitor.stale_feed_hours', 48);
    $active = Competitor::where('status', Competitor::STATUS_ACTIVE)->where('is_active', true)->get();

    $fresh = $active->filter(fn ($c) => $c->last_ingest_at && $c->last_ingest_at->diffInHours(now()) < $threshold)->count();
    $stale = $active->filter(fn ($c) => $c->last_ingest_at && $c->last_ingest_at->diffInHours(now()) >= $threshold)->count();
    $missing = $active->filter(fn ($c) => $c->last_ingest_at === null)->count();

    return [
        Stat::make('Fresh feeds', $fresh)->color('success')->icon('heroicon-o-check-circle'),
        Stat::make('Stale feeds', $stale)->color('warning')->icon('heroicon-o-clock'),
        Stat::make('Missing feeds', $missing)->color('danger')->icon('heroicon-o-x-circle'),
    ];
}
```

**`CsvIngestIssuesPage.php`**:
- Extends `Filament\Pages\Page`; uses `HasTabs` concern or the Livewire-driven Filament Tabs component in the Blade view
- `canAccess`: admin + pricing_manager only
- 4 tabs — each tab renders a Table widget filtered by issue_type + unresolved
- **Quarantine tab resolve action** (D-04 gate `->authorize('update', CompetitorCsvMapping::class)`):
  ```php
  Action::make('resolve')
      ->authorize(fn () => auth()->user()?->can('update', CompetitorCsvMapping::class) ?? false)
      ->modalHeading('Resolve Mapping Ambiguity')
      ->form(function ($record) {
          $src = storage_path('app/competitors/quarantine/' . basename($record->filename));
          $rows = file_exists($src)
              ? collect(\Spatie\SimpleExcel\SimpleExcelReader::create($src)->getRows())->take(10)->all()
              : [];
          $header = $rows[0] ?? [];
          $options = [];
          foreach (array_keys($header) as $i => $k) {
              $options[$i] = sprintf('[%d] %s', $i, $k);
          }
          return [
              Placeholder::make('preview')->content(view('filament.previews.csv-preview', ['rows' => $rows])),
              Select::make('sku_column_index')->options($options)->required(),
              Select::make('price_column_index')->options($options)->required(),
              Radio::make('decimal_format')->options(['dot' => 'Dot (1,234.56)', 'comma' => 'Comma (1.234,56)'])->default('dot')->required(),
          ];
      })
      ->action(function ($record, array $data) {
          $competitor = Competitor::findOrFail($record->competitor_id);
          CompetitorCsvMapping::updateOrCreate(
              ['competitor_id' => $competitor->id],
              [
                  'sku_column_index' => (int) $data['sku_column_index'],
                  'price_column_index' => (int) $data['price_column_index'],
                  'decimal_format' => $data['decimal_format'],
                  'detected_at' => now(),
              ]
          );

          $src = storage_path('app/competitors/quarantine/' . basename($record->filename));
          $dst = storage_path('app/competitors/incoming/' . basename($record->filename));
          if (file_exists($src)) { @rename($src, $dst); }

          IngestCompetitorCsvJob::dispatch($dst, $competitor->id)->onQueue('competitor-csv');
          $record->update(['resolved_at' => now()]);
      })
  ```

**Blade views**:
- `resources/views/filament/pages/competitor-analysis.blade.php` — wraps Filament `<x-filament::page>` with per-competitor Tabs component (one Tab per active Competitor, each embedding a SkuPriceTrendChart scoped to that competitor)
- `resources/views/filament/pages/csv-ingest-issues.blade.php` — 4-tab layout
- `resources/views/filament/widgets/stale-feed-traffic-light.blade.php` — standard Filament stats overview shell (can use default widget blade if shape matches)

**Tests**:
- `CsvIngestIssuesPageResolveActionTest` (Livewire): seed a csv_parse_error(ambiguous_mapping) + write a CSV file to `storage/app/competitors/quarantine/demo_2026-04-21.csv`; acting as pricing_manager, call Resolve with valid form data; assert CompetitorCsvMapping exists + file moved to incoming/ + IngestCompetitorCsvJob dispatched via Queue::fake() + record.resolved_at set
- `BiggestMarginDeltasTableTest`: seed Product(sell_price_pennies=null) + CompetitorPrice row; seed Product(sell_price_pennies=10000) + CompetitorPrice row; query widget → assert only the second row appears (W4 WHERE IS NOT NULL holds)
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/CsvIngestIssuesPageResolveActionTest.php tests/Feature/Competitor/BiggestMarginDeltasTableTest.php --stop-on-failure</automated>
  </verify>
  <done>2 Pages render with correct role gating; 3 widgets load; Quarantine Resolve action end-to-end tested (form → mapping created → file moved → ingest re-dispatched); BiggestMarginDeltasTable null-safety verified; Blade views exist.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: CompetitorCheckStaleCommand + StaleFeedNotification + hourly schedule + CompetitorDemoSeeder</name>
  <files>
    app/Domain/Competitor/Console/Commands/CompetitorCheckStaleCommand.php,
    app/Domain/Competitor/Notifications/StaleFeedNotification.php,
    database/seeders/CompetitorDemoSeeder.php,
    routes/console.php,
    tests/Feature/Competitor/CompetitorCheckStaleCommandTest.php,
    tests/Feature/Competitor/StaleFeedNotificationTest.php
  </files>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §10 (stale-feed cadence + dedup + notification body)
    - @app/Domain/Alerting/Models/AlertRecipient.php (receives_competitor_alerts column — added in 05-01)
    - @app/Domain/Alerting/Notifications/ (existing mailable patterns)
    - @app/Console/Commands/BaseCommand.php (perform() method)
    - @routes/console.php (current schedule — competitor:watch + competitor:sales-recache already present from 05-02 + 05-03)
    - @.planning/phases/05-competitor-analysis/05-03-SUMMARY.md (D-07 evidence shape — seeder reuses for demo margin_change)
    - @.planning/phases/01-foundation/01-05-SUMMARY.md (AlertRecipient Notifiable pattern reference)
  </read_first>
  <behavior>
    - Test: `competitor:check-stale` with no active competitors → return 0, no notifications dispatched
    - Test: `competitor:check-stale` with 1 active competitor whose last_ingest_at is 50 hours ago → dispatches StaleFeedNotification to AlertRecipients where receives_competitor_alerts=true
    - Test: `competitor:check-stale` with 1 active competitor whose last_ingest_at is 10 hours ago → NO notification (not stale yet)
    - Test: `competitor:check-stale` with a competitor where last_ingest_at IS NULL AND status='active' → IS notified (treated as stale)
    - Test: running `competitor:check-stale` TWICE within 24h for the SAME stale competitor → only first run dispatches (Cache::add dedup `competitor.stale_alert.{id}.{YYYY-MM-DD}` 24h TTL)
    - Test: Status=inactive competitor is ignored even if last_ingest_at is stale
    - Test: StaleFeedNotification email subject contains competitor.name; body contains last_ingest_at + hours_stale or 'No ingest recorded' + action URL to /admin/competitor-ingest-runs filtered by competitor_id
    - Test: `php artisan schedule:list | grep competitor:check-stale` shows HOURLY schedule with `->onOneServer()`
    - Test: CompetitorDemoSeeder runs idempotently — second run does not create duplicate Competitor rows, duplicate Suggestion rows, or duplicate CompetitorPrice rows; the demo CSV file in quarantine/ is written (overwrite fine)
  </behavior>
  <action>
**`app/Domain/Competitor/Console/Commands/CompetitorCheckStaleCommand.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Notifications\StaleFeedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class CompetitorCheckStaleCommand extends BaseCommand
{
    protected $signature = 'competitor:check-stale';
    protected $description = 'Check for stale competitor feeds and notify admins (hourly).';

    protected function perform(): int
    {
        $thresholdHours = (int) config('competitor.stale_feed_hours', 48);

        $stale = Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->where(function ($q) use ($thresholdHours) {
                $q->whereNull('last_ingest_at')
                  ->orWhere('last_ingest_at', '<', now()->subHours($thresholdHours));
            })
            ->get();

        $today = now()->format('Y-m-d');
        $notified = 0;

        foreach ($stale as $competitor) {
            $dedupKey = sprintf('competitor.stale_alert.%d.%s', $competitor->id, $today);
            if (! Cache::add($dedupKey, true, now()->addHours(24))) {
                continue;
            }

            $hoursStale = $competitor->last_ingest_at
                ? (int) now()->diffInHours($competitor->last_ingest_at)
                : null;

            $recipients = AlertRecipient::query()
                ->where('receives_competitor_alerts', true)
                ->where('is_active', true)
                ->get();

            Notification::send($recipients, new StaleFeedNotification($competitor, $hoursStale));
            $notified++;
        }

        $this->info(sprintf('Checked %d stale competitor(s); dispatched %d notification batches.', $stale->count(), $notified));
        return 0;
    }
}
```

**`app/Domain/Competitor/Notifications/StaleFeedNotification.php`:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Notifications;

use App\Domain\Competitor\Models\Competitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaleFeedNotification extends Notification
{
    public function __construct(
        public readonly Competitor $competitor,
        public readonly ?int $hoursStale,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $staleMsg = $this->hoursStale !== null
            ? sprintf('%d hours since last ingest', $this->hoursStale)
            : 'No ingest recorded';

        return (new MailMessage)
            ->subject(sprintf('[MS Ops] Stale competitor feed: %s', $this->competitor->name))
            ->line(sprintf('Competitor "%s" has not reported new prices: %s.', $this->competitor->name, $staleMsg))
            ->line(sprintf('Last ingest: %s', $this->competitor->last_ingest_at?->toDateTimeString() ?? 'never'))
            ->action('View Ingest Runs', url(sprintf('/admin/competitor-ingest-runs?tableFilters[competitor_id][value]=%d', $this->competitor->id)))
            ->line('Check the n8n workflow if this is unexpected.');
    }
}
```

**`routes/console.php`** — APPEND:
```php
Schedule::command('competitor:check-stale')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();
```

**`database/seeders/CompetitorDemoSeeder.php`** — idempotent demo fixtures (runs in local / testing environments; walkthrough checkpoint uses it instead of manual seeding):

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\{Competitor, CompetitorPrice, CsvParseError};
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class CompetitorDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 3 demo competitors with varying freshness
        $fresh = Competitor::firstOrCreate(['slug' => 'demo-fresh'], ['name' => 'Demo Fresh Competitor', 'status' => 'active', 'is_active' => true, 'last_ingest_at' => now()->subHours(2)]);
        $stale = Competitor::firstOrCreate(['slug' => 'demo-stale'], ['name' => 'Demo Stale Competitor', 'status' => 'active', 'is_active' => true, 'last_ingest_at' => now()->subHours(50)]);
        $missing = Competitor::firstOrCreate(['slug' => 'demo-missing'], ['name' => 'Demo Missing Competitor', 'status' => 'active', 'is_active' => true, 'last_ingest_at' => null]);

        // Ensure a demo Product exists (for trend chart + biggest delta widgets)
        $product = Product::firstOrCreate(['sku' => 'DEMO-SKU-001'], ['name' => 'Demo Conference Speaker', 'supplier_price_pennies' => 4000, 'sell_price_pennies' => 8500]);

        // 20+ CompetitorPrice rows across 30 days for the demo SKU (avoid unique-violation on re-seed — check first)
        $existing = CompetitorPrice::where('competitor_id', $fresh->id)->where('sku', $product->sku)->count();
        if ($existing < 20) {
            for ($d = 29; $d >= 0; $d--) {
                CompetitorPrice::firstOrCreate(
                    ['competitor_id' => $fresh->id, 'sku' => $product->sku, 'recorded_at' => now()->subDays($d)->startOfDay()],
                    ['price_pennies_gross' => 7500 + rand(-500, 500), 'price_pennies_ex_vat' => 6250 + rand(-400, 400), 'mpn' => null]
                );
            }
        }

        // Margin-change suggestion with D-07 evidence shape
        Suggestion::firstOrCreate(
            ['kind' => 'margin_change', 'subject_type' => 'App\\Domain\\Pricing\\Models\\PricingRule', 'subject_id' => 1],
            [
                'status' => 'pending',
                'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 7000],
                'evidence' => [
                    'competitor_id' => $fresh->id,
                    'competitor_name' => $fresh->name,
                    'sku' => $product->sku,
                    'our_sell_price_pennies' => 8500,
                    'our_supplier_price_pennies' => 4000,
                    'our_current_margin_bps' => 5000,
                    'proposed_margin_bps' => 7000,
                    'margin_delta_bps' => 2000,
                    'sales_count_90d' => 15,
                    'pricing_rule' => ['id' => 1, 'name' => 'Default Tier', 'scope' => 'default_tier', 'current_margin_bps' => 5000],
                    'beat_by_pennies' => 1,
                ],
                'correlation_id' => (string) \Str::uuid(),
            ]
        );

        // New-product-opportunity suggestion with supporting_competitors=2
        Suggestion::firstOrCreate(
            ['kind' => 'new_product_opportunity', 'subject_type' => null, 'subject_id' => null],
            [
                'status' => 'pending',
                'payload' => ['sku' => 'ORPHAN-DEMO-001'],
                'evidence' => [
                    'sku' => 'ORPHAN-DEMO-001',
                    'supporting_competitors' => 2,
                    'competitor_sightings' => [
                        ['competitor_id' => $fresh->id, 'name' => $fresh->name, 'price_gross_pennies' => 12000, 'recorded_at' => now()->toIso8601String()],
                        ['competitor_id' => $stale->id, 'name' => $stale->name, 'price_gross_pennies' => 11500, 'recorded_at' => now()->subDays(2)->toIso8601String()],
                    ],
                ],
                'correlation_id' => (string) \Str::uuid(),
            ]
        );

        // CSV parse error (ambiguous_mapping) + matching file in quarantine
        $quarantineDir = storage_path('app/competitors/quarantine');
        if (! is_dir($quarantineDir)) { @mkdir($quarantineDir, 0755, true); }
        $demoCsv = $quarantineDir . '/demo_2026-04-21.csv';
        if (! file_exists($demoCsv)) {
            file_put_contents($demoCsv, "foo,bar,baz\n1,2,3\n4,5,6\n");
        }
        CsvParseError::firstOrCreate(
            ['filename' => 'demo_2026-04-21.csv', 'issue_type' => 'ambiguous_mapping'],
            ['competitor_id' => $missing->id, 'context' => ['headers' => ['foo', 'bar', 'baz']]]
        );

        // Ensure ops@meetingstore.co.uk receives competitor alerts
        AlertRecipient::where('email', 'ops@meetingstore.co.uk')->update(['receives_competitor_alerts' => true]);
    }
}
```

Register in `DatabaseSeeder::run()` under a local/testing guard:
```php
if (app()->environment(['local', 'testing'])) {
    $this->call(CompetitorDemoSeeder::class);
}
```

**Tests** — `tests/Feature/Competitor/CompetitorCheckStaleCommandTest.php` + `StaleFeedNotificationTest.php`:
- `Notification::fake()` + `Cache::flush()` per test
- Assert `Notification::assertSentTo($recipient, StaleFeedNotification::class)` for stale cases; `assertNothingSent` for fresh
- Seed AlertRecipient rows with mixed receives_competitor_alerts values
- Test case: 2 stale competitors + 3 active subscribed recipients → 2 notification batches, each sent to 3 recipients
- Test case: same command run twice → second run dispatches zero (Cache::add dedup)
- `StaleFeedNotificationTest` asserts subject + body line content + action URL pattern matches /admin/competitor-ingest-runs?tableFilters[competitor_id][value]={id}
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/CompetitorCheckStaleCommandTest.php tests/Feature/Competitor/StaleFeedNotificationTest.php --stop-on-failure && php artisan schedule:list 2>/dev/null | grep -q "competitor:check-stale"</automated>
  </verify>
  <done>CompetitorCheckStaleCommand runs hourly on schedule; 48h threshold + 24h dedup verified; StaleFeedNotification email has actionable content; AlertRecipient receives_competitor_alerts filter applied; CompetitorDemoSeeder idempotently seeds the human-verify walkthrough fixtures; 2 Pest tests green.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: Human visual QA — Filament UI walkthrough (CompetitorDemoSeeder-driven)</name>
  <files>
    (checkpoint — no files written; operator manually exercises the UI using CompetitorDemoSeeder fixtures shipped in Task 2)
  </files>
  <what-built>
    - 3 Filament Resources (from 05-04a): CompetitorPriceResource, CompetitorIngestRunResource, CsvParseErrorResource
    - SuggestionResource kind-specific Approve actions (from 05-04a): margin_change + new_product_opportunity
    - AlertRecipientResource receives_competitor_alerts toggle (from 05-04a)
    - CompetitorAnalysisPage at /admin/competitor-analysis with trend chart + biggest deltas + stale-feed traffic light
    - CsvIngestIssuesPage at /admin/csv-ingest-issues with 4 tabs (Quarantine with Resolve modal, Orphans, Encoding Errors, Value Errors)
    - CompetitorCheckStaleCommand hourly + StaleFeedNotification
    - CompetitorDemoSeeder fixture generator
  </what-built>
  <how-to-verify>
    1. Start the app: `php artisan serve` + `php artisan horizon` + `php artisan schedule:work` in separate terminals
    2. Run the demo seeder (idempotent — safe to re-run): `php artisan migrate:fresh --seed` (which runs CompetitorDemoSeeder via DatabaseSeeder's environment guard)
    3. Log in as admin (admin@meetingstore.co.uk / password from Phase 1 DatabaseSeeder)
    4. Visit `/admin/competitor-analysis`:
       - Confirm StaleFeedTrafficLight shows 1 fresh / 1 stale / 1 missing (matches CompetitorDemoSeeder output)
       - Confirm SkuPriceTrendChart renders for DEMO-SKU-001
       - Switch filter 7 → 30 → 90 → 365 — chart rebuilds each time without full-page reload
       - Confirm BiggestMarginDeltasTable shows rows sorted by ABS(delta) DESC; products without sell_price are NOT shown
    5. Visit `/admin/csv-ingest-issues`:
       - Quarantine tab → see demo_2026-04-21.csv row
       - Click Resolve → modal shows first-10-rows preview of the 3-row demo CSV
       - Pick sku_column_index=0, price_column_index=1, decimal_format=dot → submit
       - Modal closes; check that CompetitorCsvMapping row now exists for demo-missing competitor; file moved from quarantine/ to incoming/; Horizon /horizon shows an IngestCompetitorCsvJob pushed on competitor-csv queue
       - Orphans tab → see ORPHAN-DEMO-001 suggestion linking to SuggestionResource inbox
    6. Visit `/admin/suggestions`:
       - Filter by kind=margin_change → click seeded suggestion → Approve → modal shows "Margin: 5000 bps → 7000 bps (Δ 2000 bps)" → confirm → ApplySuggestionJob dispatched
       - Filter by kind=new_product_opportunity → supporting_competitors badge shows 2 → Approve → stub applier logs "Phase 6 will wire supplier-request-list integration" in laravel.log
    7. Visit `/admin/alert-recipients`:
       - Confirm ops@meetingstore.co.uk row has Receives Competitor Alerts = on
       - Edit → toggle appears after Receives CRM Alerts and persists on save
    8. Run `php artisan competitor:check-stale` manually → observe notifications dispatched to ops@ (log driver writes to storage/logs/laravel.log)
    9. Re-run `php artisan competitor:check-stale` immediately → observe ZERO new notifications (24h dedup holds)
    10. Log in as pricing_manager → confirm:
        - Access to 3 Resources + Analysis Page + Ingest Issues Page
        - Resolve action is clickable on Quarantine tab (D-04)
    11. Log in as sales → confirm:
        - Access to CompetitorPrice + CompetitorIngestRun ONLY
        - No access to CsvParseError
        - Analysis Page viewable (read)
    12. Log in as read_only → confirm zero Competitor Resources/Pages visible in navigation
  </how-to-verify>
  <action>Pause execution and wait for human to perform the 12-point walkthrough. Do NOT proceed to 05-05 Plan until operator returns the resume-signal.</action>
  <verify>
    <automated>echo "Human checkpoint — no automated verification; resume-signal acknowledged by operator."</automated>
  </verify>
  <done>Operator returns "approved" after all 12 walkthrough checks pass, OR returns a defect list that triggers a fix cycle before 05-05 begins.</done>
  <resume-signal>Type "approved" when the UI walkthrough confirms all 12 checks, OR describe issues for fix cycle.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Filament HTTP → Resolve action | Admin/pricing_manager action; file-system rename with basename() only |
| Resolve form → file-system | basename() strips path separators; never reads user-supplied absolute path |
| Scheduler → CompetitorCheckStaleCommand | System-triggered; no user input |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-04b-01 | Elevation of Privilege | Resolve action path traversal | mitigate | `basename($record->filename)` before path join; filename regex enforced upstream in 05-02 watcher. |
| T-05-04b-02 | Denial of Service | stale-feed notification flood | mitigate | Cache::add 24h dedup key per (competitor, date); max 1 notification per competitor per day. |
| T-05-04b-03 | Information Disclosure | StaleFeedNotification competitor.name | accept | Competitor names are internal/commercial; recipients are subscribed AlertRecipient only. |
| T-05-04b-04 | Tampering | Resolve form arbitrary column index | mitigate | Select options derived from actual CSV header (not user input); `(int) cast` before persistence; if header count = 0 (missing file), form blocks submission. |
| T-05-04b-05 | Information Disclosure | CompetitorDemoSeeder in production | mitigate | Seeder registration gated by `app()->environment(['local', 'testing'])` in DatabaseSeeder. |
</threat_model>

<verification>
- All 4 Pest tests in this plan green
- `php artisan schedule:list` shows 3 Phase 5 entries (competitor:watch, competitor:sales-recache, competitor:check-stale)
- Human-verify 12-point walkthrough passes
- CompetitorDemoSeeder idempotent on re-run
- BiggestMarginDeltasTable W4 null-safety verified via Pest
- No Phase 1-4 regressions
</verification>

<success_criteria>
- CompetitorAnalysisPage + CsvIngestIssuesPage shipped
- 3 widgets render correctly; BiggestMarginDeltasTable excludes null sell_price products
- CsvIngestIssuesPage Resolve action end-to-end — form → CompetitorCsvMapping created → file moved → IngestCompetitorCsvJob re-dispatched
- CompetitorCheckStaleCommand scheduled hourly + 24h dedup working
- StaleFeedNotification dispatches via receives_competitor_alerts recipients
- CompetitorDemoSeeder makes the human-verify checkpoint repeatable
- Human-verify checkpoint cleared
</success_criteria>

<output>
Create `.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md` documenting:
- Whether the Resolve action form-building approach (iterating CSV rows in a Filament modal form) worked or required splitting into a dedicated Livewire component
- Whether Filament 3.3 ChartWidget supports the dynamic per-competitor colour palette or needed override
- Any deviation from the 4-tab CsvIngestIssuesPage shape (Livewire Tabs quirks encountered)
- BiggestMarginDeltasTable query performance on the seeded fixture (row count, explain plan summary — informs 10M-row partitioning decision deferred per CONTEXT)
- CompetitorDemoSeeder notes: any fixture that required adjustment (e.g., if PricingRule id=1 doesn't exist in the test environment the seeder should guard)
- Handoff to 05-05: Phase 5 UI surface is COMPLETE; retention + guardrails + verification plan ships next
</output>
