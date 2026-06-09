---
phase: 260609-nku
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/{TS}_create_stock_divergence_findings_table.php
  - app/Domain/Products/Models/StockDivergenceFinding.php
  - app/Console/Commands/AuditStockDivergenceCommand.php
  - app/Providers/AppServiceProvider.php
  - routes/console.php
  - app/Filament/Pages/StockDivergencePage.php
  - resources/views/filament/pages/stock-divergence.blade.php
  - app/Filament/Widgets/StockDivergenceWidget.php
  - resources/views/filament/widgets/stock-divergence-widget.blade.php
  - app/Domain/Dashboard/Services/SnapshotAggregator.php
  - app/Domain/Dashboard/Services/NotificationCentreAggregator.php
  - app/Providers/Filament/AdminPanelProvider.php
  - tests/Feature/Dashboard/HomeDashboardPageTest.php
  - tests/Feature/Console/AuditStockDivergenceCommandTest.php
  - tests/Feature/Filament/StockDivergencePageTest.php
  - tests/Unit/Domain/Dashboard/SnapshotAggregatorStockDivergenceTest.php
  - resources/views/filament/pages/category-audit.blade.php
autonomous: true
requirements:
  - 260609-nku
must_haves:
  truths:
    - "Operator can detect SKUs where MS local stock=0 + every fresh supplier reports 0 + Woo stock>0 (phantom-stock divergence)"
    - "Operator can view a sortable list of phantom-stock SKUs at /admin/stock-divergence with phantom_units descending by default"
    - "Operator can bulk-resync up to 100 selected divergent SKUs back to Woo via Filament action invoking products:resync-to-woo"
    - "Operator can resync a single divergent SKU per-row via the same action"
    - "Audit runs unattended weekly Mon 09:15 London via cron"
    - "Home dashboard surfaces divergent count + total phantom units in StockDivergenceWidget (16th widget) and notification centre stale_data bucket"
    - "Cross-link from /admin/category-audit footer points operators at /admin/stock-divergence and vice versa"
    - "Fresh-supplier predicate is sourced ONLY from SupplierFreshnessResolver::freshSupplierIds() — no duplicated DATEDIFF SQL"
  artifacts:
    - path: "database/migrations/{TS}_create_stock_divergence_findings_table.php"
      provides: "stock_divergence_findings snapshot table"
      contains: "Schema::create('stock_divergence_findings'"
    - path: "app/Domain/Products/Models/StockDivergenceFinding.php"
      provides: "Eloquent model + casts"
      exports: ["StockDivergenceFinding"]
    - path: "app/Console/Commands/AuditStockDivergenceCommand.php"
      provides: "products:audit-stock-divergence command (engine)"
      contains: "products:audit-stock-divergence"
    - path: "app/Filament/Pages/StockDivergencePage.php"
      provides: "/admin/stock-divergence page"
      contains: "StockDivergencePage"
    - path: "app/Filament/Widgets/StockDivergenceWidget.php"
      provides: "Home dashboard widget"
      contains: "StockDivergenceWidget"
    - path: "tests/Feature/Console/AuditStockDivergenceCommandTest.php"
      provides: "6 engine cases (A-F)"
      min_lines: 120
    - path: "tests/Feature/Filament/StockDivergencePageTest.php"
      provides: "4 role + filter + bulk-action cases"
      min_lines: 80
    - path: "tests/Unit/Domain/Dashboard/SnapshotAggregatorStockDivergenceTest.php"
      provides: "Aggregator shape case"
      min_lines: 30
  key_links:
    - from: "app/Console/Commands/AuditStockDivergenceCommand.php"
      to: "app/Domain/Sync/Services/SupplierFreshnessResolver.php"
      via: "freshSupplierIds() return → whereIn supplier_id"
      pattern: "freshSupplierIds\\("
    - from: "app/Console/Commands/AuditStockDivergenceCommand.php"
      to: "app/Domain/Sync/Services/WooClient.php"
      via: "$this->woo->get('products', ['include'=>...,'orderby'=>'include'])"
      pattern: "\\$this->woo->get\\('products'"
    - from: "app/Filament/Pages/StockDivergencePage.php"
      to: "app/Console/Commands/ResyncProductsToWooCommand.php"
      via: "Artisan::call('products:resync-to-woo', ['--skus' => ...])"
      pattern: "products:resync-to-woo"
    - from: "routes/console.php"
      to: "products:audit-stock-divergence"
      via: "Schedule::command(...)->cron('15 9 * * 1')->timezone('Europe/London')"
      pattern: "products:audit-stock-divergence"
    - from: "app/Domain/Dashboard/Services/SnapshotAggregator.php"
      to: "app/Domain/Products/Models/StockDivergenceFinding.php"
      via: "computeStockDivergence() reads count + sum(phantom_units) + max(audited_at)"
      pattern: "computeStockDivergence"
    - from: "app/Providers/Filament/AdminPanelProvider.php"
      to: "app/Filament/Widgets/StockDivergenceWidget.php"
      via: "widgets() array entry (16th)"
      pattern: "StockDivergenceWidget"
---

<objective>
Surface "phantom stock" divergences (MS=0 + every fresh supplier=0 + Woo>0) via a weekly artisan audit, a /admin/stock-divergence Filament page with bulk-resync, a home-dashboard widget, and a notification-centre entry. Detection + opt-in correction — NOT auto-correction. Mirrors the 260607-t6w category-audit shape (predicate → snapshot → page → widget → bulk action) and reuses 260608-g8x's SupplierFreshnessResolver for the fresh-supplier predicate so the rule lives in exactly one place.

Purpose: Today's live observation (`45-243-224` Ergotron arm: MS=0, suppliers fresh-and-empty, Woo=7) confirms a continuous revenue/UX leak. Customers see stock that doesn't exist, order it, then hit backorder limbo. We currently have ZERO visibility into the scale. This plan ships detection + a one-click correction surface.

Output: One new migration, one new model, one new artisan command, one new Filament page + blade, one new Filament widget + blade, one new SnapshotAggregator method, one extended NotificationCentre bucket, one extended cron schedule, three test files (6 + 4 + 1 cases), one cross-reference edit to the category-audit footer.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Products/Console/Commands/AuditProductCategoriesCommand.php
@app/Console/Commands/BackfillCategoryFromWooCommand.php
@app/Domain/Sync/Services/SupplierFreshnessResolver.php
@app/Domain/Sync/Services/WooClient.php
@app/Domain/Products/Models/CategoryAuditFinding.php
@app/Filament/Pages/CategoryAuditPage.php
@resources/views/filament/pages/category-audit.blade.php
@app/Filament/Widgets/CategoryAuditWidget.php
@app/Domain/Dashboard/Services/SnapshotAggregator.php
@app/Domain/Dashboard/Services/NotificationCentreAggregator.php
@app/Console/Commands/ResyncProductsToWooCommand.php
@routes/console.php
@app/Providers/AppServiceProvider.php
@app/Providers/Filament/AdminPanelProvider.php
@tests/Feature/Dashboard/HomeDashboardPageTest.php

<interfaces>
<!-- Key contracts the executor will touch — extracted so no exploration is needed. -->

From app/Domain/Sync/Services/SupplierFreshnessResolver.php:
- `freshSupplierIds(): Collection<string>` — returns supplier ids whose latest recorded_at is within the freshness window. SAME ids used in the supplier_offer_snapshots.supplier_id column.

From app/Domain/Sync/Services/WooClient.php (line ~94):
- `get(string $endpoint, array $query = []): array` — Guzzle-wrapped Woo REST GET. For batched fetch use endpoint='products' with `['include' => 'id1,id2,...', 'per_page' => count, 'orderby' => 'include']`.

From app/Console/Commands/ResyncProductsToWooCommand.php:
- Signature: `products:resync-to-woo {--skus= : Comma-separated SKUs to resync}` (CONFIRM during Task 2 — match what the file actually accepts; spec assumes --skus=comma-list).

From app/Domain/Products/Models/CategoryAuditFinding.php:
- Shape to mirror: $fillable + $casts (timestamps + integers) + table name property; no relationships.

Same-shape predecessors:
- 260607-t6w: TRUNCATE-and-replace snapshot, cron-driven, page+widget+bulk action.
- 260608-g8x: SupplierFreshnessResolver source of truth, supplier_freshness_snapshots, 15th widget.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Migration + Eloquent model for stock_divergence_findings</name>
  <files>
    database/migrations/{TS}_create_stock_divergence_findings_table.php,
    app/Domain/Products/Models/StockDivergenceFinding.php
  </files>
  <action>
    Create the migration `database/migrations/{TS}_create_stock_divergence_findings_table.php` (TS = current `date +%Y_%m_%d_%H%i%s` per artisan convention). Schema::create('stock_divergence_findings', ...) with columns:
    - `id` bigIncrements
    - `sku` string(64) — the MS SKU
    - `name` string(255) nullable — display name copy at audit time
    - `woo_product_id` unsignedInteger — Woo product id
    - `ms_stock_quantity` integer — always 0 in current scope but stored for forensic clarity
    - `woo_stock_quantity` integer — what Woo claims
    - `phantom_units` integer — woo_stock_quantity - ms_stock_quantity (the sortable / headline number)
    - `woo_last_modified` dateTime nullable — Woo's date_modified at audit time
    - `ms_last_synced_at` dateTime nullable — products.last_synced_at at audit time
    - `status` enum('woo_overcount') — single-value today, room for woo_undercount + woo_missing later without migration churn
    - `run_id` string(26) — ulid identifying the audit run
    - `audited_at` dateTime — wall-clock audit time
    - `timestamps()` — created_at + updated_at
    Indexes: index(['sku']), index(['run_id']), index(['status']), index(['phantom_units']) — phantom_units index supports the page's default DESC sort.

    Create `app/Domain/Products/Models/StockDivergenceFinding.php` mirroring `app/Domain/Products/Models/CategoryAuditFinding.php` exactly (namespace, $table = 'stock_divergence_findings', $fillable with every column except id/timestamps, $casts with integers + datetimes). No relationships, no scopes.

    DO NOT inline migration or model code in any text artefact — write to the files via the editor.

    Atomic commit at end of task: `feat(products): stock_divergence_findings table + model (260609-nku)`
  </action>
  <verify>
    <automated>php artisan migrate --pretend 2>&amp;1 | findstr stock_divergence_findings &amp;&amp; php artisan migrate &amp;&amp; php artisan tinker --execute="echo \App\Domain\Products\Models\StockDivergenceFinding::query()-&gt;count();"</automated>
  </verify>
  <done>
    Migration runs cleanly; `stock_divergence_findings` table exists; `StockDivergenceFinding::count()` returns 0 without exception; commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 2: AuditStockDivergenceCommand (the engine)</name>
  <files>
    app/Console/Commands/AuditStockDivergenceCommand.php
  </files>
  <action>
    Create `app/Console/Commands/AuditStockDivergenceCommand.php` (path mirrors BackfillCategoryFromWooCommand — Woo-touching commands live at app/Console/Commands/, NOT app/Domain/Products/Console/Commands/). Extend `App\Console\Commands\BaseCommand` (same base as BackfillCategoryFromWooCommand). Signature:
    `products:audit-stock-divergence {--limit=0 : Cap candidate set (0=unbounded)} {--chunk=50 : Woo IDs per batch (Woo per_page cap 100)} {--dry-run : Print outcomes without writing snapshot}`

    Constructor DI: `WooClient $woo`, `SupplierFreshnessResolver $freshness`.

    `perform()` (or `handle()` — match BaseCommand's expected entry point) algorithm:
    1. `$runId = (string) Str::ulid();` `$auditedAt = now();` `$chunkSize = (int) $this->option('chunk');` `$limit = (int) $this->option('limit');`
    2. `$freshIds = $this->freshness->freshSupplierIds()->all();` — DO NOT duplicate the DATEDIFF SQL; the resolver is the single source of truth (drift-prevention principle).
    3. Build candidate query: Eloquent on `Product` model (verify class — likely `App\Domain\Products\Models\Product`). Filter:
       - `where('status', 'publish')`
       - `whereNotNull('woo_product_id')`
       - `where('stock_quantity', 0)`
       - `whereNotExists(function ($q) use ($freshIds) { $q->select(DB::raw(1))->from('supplier_offer_snapshots as s')->whereColumn('s.sku', DB::raw('LOWER(TRIM(products.sku))'))->whereIn('s.supplier_id', $freshIds === [] ? ['__NONE__'] : $freshIds)->where('s.stock', '>', 0)->where('s.recorded_at', '>=', now()->subDays(7)); })` — the `__NONE__` sentinel mirrors 260608-g8x's empty-set guard so whereIn never collapses to a true match.
       - Apply `->limit($limit)` only when `$limit > 0`.
       - Use `->cursor()` to stream — 3,000+ candidates must not blow memory.
    4. Initialise counters: `candidates_scanned, woo_responses_received, matched, divergent_found, woo_not_found, error, total_phantom_units` (all int).
    5. Chunk candidates with `->chunk($chunkSize)` over the cursor (gather into a Collection, then chunk; or use LazyCollection::chunk if Product::cursor() returns LazyCollection). For each chunk:
       - Build `$wooIds = $chunk->pluck('woo_product_id')->all();`
       - `try { $response = $this->woo->get('products', ['include' => implode(',', $wooIds), 'per_page' => count($wooIds), 'orderby' => 'include']); } catch (\Throwable $e) { $error += count($chunk); $this->error('Woo batch failed: '.$e->getMessage()); continue; }`
       - `$woo_responses_received += count($response);`
       - Build lookup map: `$byId = collect($response)->keyBy('id');` — Woo response uses `id`, not `woo_product_id`.
       - For each candidate `$p` in `$chunk`:
         - `$candidates_scanned++;`
         - `$wooRow = $byId->get($p->woo_product_id);`
         - If `$wooRow === null`: `$woo_not_found++;` continue.
         - Coerce `$wooQty = (int) ($wooRow['stock_quantity'] ?? 0);`
         - If `$wooQty &lt;= 0`: `$matched++;` continue.
         - Divergent: `$divergent_found++; $phantom = $wooQty - 0; $total_phantom_units += $phantom;` Build row array: {sku, name (from $p->name), woo_product_id, ms_stock_quantity=0, woo_stock_quantity=$wooQty, phantom_units=$phantom, woo_last_modified (parse $wooRow['date_modified'] nullable), ms_last_synced_at ($p->last_synced_at), status='woo_overcount', run_id=$runId, audited_at=$auditedAt, created_at=now(), updated_at=now()}. Push to a local `$findings` Collection.
       - `usleep(200000);` — 200ms throttle between Woo batches (matches today's manual probe + protects CWP IO concurrency).
    6. If `$this->option('dry-run')`:
       - Print counters table via `$this->table([...], [...])`.
       - Print top-20 sample sorted by phantom_units DESC.
       - Return 0. NO DB writes.
    7. Live path: wrap in `DB::transaction(function () use ($findings) { DB::table('stock_divergence_findings')->truncate(); foreach ($findings->chunk(500) as $batch) { DB::table('stock_divergence_findings')->insert($batch->all()); } });` — TRUNCATE-and-replace identical to 260607-t6w.
    8. Final summary table: candidates_scanned / woo_responses_received / matched / divergent_found / woo_not_found / error / **total_phantom_units** (headline number). Return 0.

    Catch and log: any per-chunk exception increments `$error` by `count($chunk)` and continues — never aborts the whole run on a transient Woo 5xx.

    DO NOT inline command code in any text artefact — write to the file via the editor.

    Atomic commit at end of task: `feat(products): audit-stock-divergence command (engine) (260609-nku)`
  </action>
  <verify>
    <automated>php artisan list | findstr audit-stock-divergence &amp;&amp; php artisan products:audit-stock-divergence --dry-run --limit=1 2>&amp;1</automated>
  </verify>
  <done>
    Command resolves via `php artisan list`; --dry-run --limit=1 runs without exception and prints the counters table; no rows written to stock_divergence_findings during dry-run; commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 3: Register command + schedule weekly Mon 09:15 London cron</name>
  <files>
    app/Providers/AppServiceProvider.php,
    routes/console.php
  </files>
  <action>
    Add `\App\Console\Commands\AuditStockDivergenceCommand::class` to the `$commands` array in `app/Providers/AppServiceProvider.php` (mirror the existing registration block where `BackfillCategoryFromWooCommand` or similar Console/Commands entries are registered — match the namespace exactly).

    Append to `routes/console.php` AFTER the existing `products:audit-categories` entry (line ~332, Fri 22:00 London) so the two audit crons sit together:

    ```
    // Quick task 260609-nku — Weekly stock-divergence audit Mon 09:15 London.
    //
    // Mon 09:00 is taken by woo:import-products safety-net retry (line 161-166)
    // and Mon 09:05 by supplier:db-sync safety-net retry (line 168-173). 09:15
    // sits AFTER both safety-net retries so today's woo stock_quantity values
    // are guaranteed fresh in the local products table before the audit's
    // NOT EXISTS subquery runs. timezone('Europe/London') resolves GMT/BST.
    //
    // TRUNCATE-and-replaces stock_divergence_findings — snapshot semantics
    // identical to 260607-t6w category_audit_findings.
    Schedule::command('products:audit-stock-divergence')
        ->cron('15 9 * * 1') // Mon at 09:15 (cron DOW: 1=Mon)
        ->withoutOverlapping(60)
        ->onOneServer()
        ->timezone('Europe/London')
        ->description('Weekly stock-divergence audit (Mon 09:15 London) — phantom-stock detection (260609-nku)');
    ```

    Slot choice rationale: the spec proposed Mon 09:00 but `woo:import-products` Mon-Fri safety-net retry already fires at `cron('0 9 * * 1-5')` and `supplier:db-sync` safety-net at `cron('5 9 * * 1-5')`. Mon 09:15 is empty AND sits AFTER both safety-net retries, which guarantees products.stock_quantity reflects today's freshest Woo pull before the audit reads it. This is strictly BETTER than 09:00 — keep 09:15.

    Atomic commit at end of task: `chore(commands,schedule): register + cron products:audit-stock-divergence Mon 09:15 London (260609-nku)`
  </action>
  <verify>
    <automated>php artisan schedule:list 2>&amp;1 | findstr audit-stock-divergence</automated>
  </verify>
  <done>
    `schedule:list` shows `products:audit-stock-divergence` scheduled at Mon 09:15 Europe/London with withoutOverlapping + onOneServer; command class registered in AppServiceProvider; commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 4: StockDivergencePage Filament page + bulk resync action</name>
  <files>
    app/Filament/Pages/StockDivergencePage.php,
    resources/views/filament/pages/stock-divergence.blade.php
  </files>
  <action>
    Create `app/Filament/Pages/StockDivergencePage.php` mirroring `app/Filament/Pages/CategoryAuditPage.php` exactly for nav + visibility shape:
    - `protected static ?string $navigationGroup = 'Catalogue';`
    - `protected static ?int $navigationSort = 17;` (CategoryAuditPage is 15 per spec — 17 sits after the existing 16 widget/page mid-band; bump if CategoryAuditPage actually uses a different sort)
    - `protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';`
    - `protected static ?string $title = 'Stock Divergence';`
    - `protected static string $view = 'filament.pages.stock-divergence';`
    - `protected static ?string $slug = 'stock-divergence';`
    - `public static function canAccess(): bool` + `public static function shouldRegisterNavigation(): bool` — admin OR pricing_manager (mirror CategoryAuditPage's role check verbatim).

    Use Filament's HasTable trait + `table(Table $table)` (mirror CategoryAuditPage's pattern). Source: `StockDivergenceFinding::query()`.

    Columns (TextColumn):
    - `sku` — fontFamily mono, copyable, searchable
    - `name` — limit(50), tooltip(fn ($record) =&gt; $record-&gt;name), searchable
    - `ms_stock_quantity` — badge, label('MS qty') — visual "always 0" confirmation column
    - `woo_stock_quantity` — badge color('warning'), sortable, label('Woo qty')
    - `phantom_units` — badge color('danger'), sortable, label('Phantom diff') — default sort DESC
    - `woo_last_modified` — since-format (->dateTime() + ->since())
    - `ms_last_synced_at` — since-format
    - `audited_at` — since-format

    Default sort: `->defaultSort('phantom_units', 'desc')`.

    Filters:
    - `Tables\Filters\Filter::make('phantom_min')` with `->form([Forms\Components\TextInput::make('phantom_min')->numeric()->default(0)->label('Min phantom units')])` and `->query(fn ($q, $data) => filled($data['phantom_min']) ? $q->where('phantom_units', '>=', (int) $data['phantom_min']) : $q)` — operator types "5" to drop noise rows.
    - Brand multi-select filter: derive brand list from joined products table OR `StockDivergenceFinding::query()->distinct()->orderBy('brand')->pluck('brand')` IF brand denormalised onto finding (NOTE: brand is NOT in the migration as written — if brand filter is required, EITHER add brand column to Task 1 migration OR join products on sku at query time. RECOMMENDED: leave brand off v1; the spec calls for it but phantom_min alone is enough triage filter. If executor wants brand, they MUST extend Task 1 migration in the same commit and update Task 6 tests).

    Per-row actions:
    - `Tables\Actions\Action::make('view_on_storefront')->url(fn ($record) => rtrim(config('services.woo.storefront_url'), '/').'/?p='.$record->woo_product_id)->openUrlInNewTab()->icon('heroicon-o-arrow-top-right-on-square')->label('View on Woo')`
    - `Tables\Actions\Action::make('resync')->requiresConfirmation()->modalDescription(fn ($record) => "Push MS stock=0 over Woo's phantom {$record->woo_stock_quantity}?")->action(function ($record) { Artisan::call('products:resync-to-woo', ['--skus' => $record->sku]); Notification::make()->title('Resync queued')->body("SKU {$record->sku} pushed to Woo")->success()->send(); })->icon('heroicon-o-arrow-path')->label('Resync to Woo')`

    Bulk action:
    - `Tables\Actions\BulkAction::make('resync_selected')->requiresConfirmation()->modalDescription(fn (Collection $records) => 'Resync '.$records->count().' SKUs ('.$records->sum('phantom_units').' phantom units total) to Woo?')->action(function (Collection $records) { if ($records->count() > 100) { Notification::make()->title('Too many selected')->body('Cap is 100 SKUs per bulk operation. Narrow your selection.')->danger()->send(); return; } $skus = $records->pluck('sku')->implode(','); Artisan::call('products:resync-to-woo', ['--skus' => $skus]); Notification::make()->title('Bulk resync queued')->body($records->count().' SKUs pushed to Woo')->success()->send(); })->icon('heroicon-o-arrow-path')->label('Resync selected to Woo')->deselectRecordsAfterCompletion()`

    Create `resources/views/filament/pages/stock-divergence.blade.php` mirroring `resources/views/filament/pages/category-audit.blade.php`:
    - `<x-filament-panels::page>` wrapper
    - `{{ $this->table }}` slot
    - Footer banner: count + total phantom units + last-run timestamp (`StockDivergenceFinding::max('audited_at')`) + "Next run: Mon 09:15 London" hint + explanation: "Phantom stock = Woo claims qty > 0 but MS's confirmed-fresh suppliers all report 0. Bulk-resync pushes MS's 0 over Woo's phantom number."
    - Cross-link line (per Task 7): "See also /admin/category-audit (260607-t6w) for taxonomy issues."

    DO NOT inline page or blade source in any text artefact — write to the files via the editor.

    Atomic commit at end of task: `feat(stock-divergence): /admin/stock-divergence Filament page + filters + bulk resync action (260609-nku)`
  </action>
  <verify>
    <automated>php artisan route:list | findstr /C:"admin/stock-divergence" &amp;&amp; php artisan view:clear</automated>
  </verify>
  <done>
    Page resolves at /admin/stock-divergence (HTTP 200 for admin user, 403 for sales user — Task 6 verifies); navigation entry visible under Catalogue group; commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 5: StockDivergenceWidget + SnapshotAggregator + NotificationCentre + register as 16th widget</name>
  <files>
    app/Filament/Widgets/StockDivergenceWidget.php,
    resources/views/filament/widgets/stock-divergence-widget.blade.php,
    app/Domain/Dashboard/Services/SnapshotAggregator.php,
    app/Domain/Dashboard/Services/NotificationCentreAggregator.php,
    app/Providers/Filament/AdminPanelProvider.php,
    tests/Feature/Dashboard/HomeDashboardPageTest.php
  </files>
  <action>
    Create `app/Filament/Widgets/StockDivergenceWidget.php` extending `Filament\Widgets\StatsOverviewWidget` (mirror `app/Filament/Widgets/CategoryAuditWidget.php` exactly):
    - `protected function getStats(): array` returning three `Stat::make` entries:
      1. `Stat::make('Divergent SKUs', $count)->description('Phantom stock detected')->descriptionIcon('heroicon-m-exclamation-triangle')->color($count > 0 ? 'danger' : 'success')->url('/admin/stock-divergence')`
      2. `Stat::make('Total phantom units', $totalPhantom)->description('Woo overcount across all SKUs')->color($totalPhantom > 0 ? 'warning' : 'success')`
      3. `Stat::make('Last audited', $lastRunAt ? $lastRunAt->diffForHumans() : 'never')->description('Next run: Mon 09:15 London')`
    - Data source: read from `SnapshotAggregator::computeStockDivergence()` OR direct query on `StockDivergenceFinding` if SnapshotAggregator caches to `dashboard_snapshots` (match CategoryAuditWidget's pattern verbatim — if it queries directly, do the same; if it reads snapshot, do the same).
    - `public static function canView(): bool` — admin OR pricing_manager (mirror CategoryAuditWidget).

    Create `resources/views/filament/widgets/stock-divergence-widget.blade.php` — only if CategoryAuditWidget uses an explicit view file; if it inherits StatsOverviewWidget's default, skip the blade (verify by reading CategoryAuditWidget — match exactly).

    Extend `app/Domain/Dashboard/Services/SnapshotAggregator.php` — add `public function computeStockDivergence(): array` at ~line 466 (next to `computeCategoryAuditHealth`):
    ```
    return [
        'count' => StockDivergenceFinding::count(),
        'total_phantom_units' => (int) StockDivergenceFinding::sum('phantom_units'),
        'last_run_at' => optional(StockDivergenceFinding::query()->max('audited_at'))->toIso8601String(),
    ];
    ```
    Wire into `computeAll()` map under key `stock_divergence`.

    Extend `app/Domain/Dashboard/Services/NotificationCentreAggregator.php` — add an entry to the `stale_data` bucket mirroring `stale_feeds`:
    ```
    'stock_divergence' => [
        'count' => $sd['count'],
        'total_phantom_units' => $sd['total_phantom_units'],
        'link' => '/admin/stock-divergence',
        'label' => 'Phantom stock SKUs',
    ],
    ```
    where `$sd = $this->aggregator->computeStockDivergence()` (or read from snapshot if NotificationCentre reads from cached snapshots).

    Register widget in `app/Providers/Filament/AdminPanelProvider.php` — append `\App\Filament\Widgets\StockDivergenceWidget::class` to the widgets() array AFTER `SupplierFreshnessWidget` (currently 15th per 260608-g8x). This MAKES it the 16th widget.

    Update `tests/Feature/Dashboard/HomeDashboardPageTest.php` — find the hardcoded widget count assertion (currently expecting 15) and bump to 16. This MUST land in the same commit as the widget registration — otherwise CI fails between commit-5 and commit-6.

    DO NOT inline widget / aggregator / blade source in any text artefact — write to the files via the editor.

    Atomic commit at end of task: `feat(dashboard): StockDivergenceWidget + SnapshotAggregator computeStockDivergence + NotificationCentre wiring (260609-nku)`
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Dashboard/HomeDashboardPageTest.php 2>&amp;1</automated>
  </verify>
  <done>
    HomeDashboardPageTest passes with 16-widget assertion; SnapshotAggregator::computeStockDivergence() returns array with count + total_phantom_units + last_run_at; NotificationCentre stale_data bucket includes stock_divergence entry; widget registered 16th; commit landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 6: Pest cases — engine (6) + page (4) + aggregator (1)</name>
  <files>
    tests/Feature/Console/AuditStockDivergenceCommandTest.php,
    tests/Feature/Filament/StockDivergencePageTest.php,
    tests/Unit/Domain/Dashboard/SnapshotAggregatorStockDivergenceTest.php
  </files>
  <behavior>
    AuditStockDivergenceCommandTest (6 cases):
    - **A (the headline live bug):** Seed Product(sku='45-243-224', stock_quantity=0, woo_product_id=8502, status='publish', last_synced_at=now()). Seed fresh supplier (id='ingram', recorded_at=now()-1h) row in supplier_offer_snapshots(sku='45-243-224', supplier_id='ingram', stock=0, recorded_at=now()-1h). Mock WooClient::get('products', ...) to return [['id' => 8502, 'stock_quantity' => 7, 'date_modified' => '2026-05-31T07:54:57']]. Run command. Assert: `stock_divergence_findings` has exactly 1 row with sku='45-243-224', phantom_units=7, woo_stock_quantity=7, ms_stock_quantity=0, status='woo_overcount'.
    - **B (fresh supplier has real stock — NOT phantom):** Same product. Supplier row reports stock=5 (real stock backs the SKU). Run command. Assert: 0 rows written. The NOT EXISTS subquery filters this candidate out before Woo is even called.
    - **C (MS has stock — not a candidate):** Product.stock_quantity=10. Run command. Assert: 0 rows written.
    - **D (Woo agrees with MS=0 — matched, not divergent):** Product MS=0, fresh supplier=0, Woo returns stock_quantity=0. Run command. Assert: 0 rows written. `matched` counter incremented (verify via command output capture).
    - **E (Woo product not found — graceful skip):** Product MS=0, fresh supplier=0, woo_product_id=99999, Woo response excludes id 99999. Run command. Assert: 0 rows written. NO exception. `woo_not_found` counter incremented.
    - **F (dry-run is read-only):** 3 candidates queued. Run with --dry-run. Assert: `stock_divergence_findings` count unchanged (whatever it was at the start, still is). Counters table printed to output.

    StockDivergencePageTest (4 cases):
    - Admin user lands on /admin/stock-divergence → 200.
    - Pricing_manager user lands on /admin/stock-divergence → 200.
    - Sales user lands on /admin/stock-divergence → 403 (or NotFound — match what CategoryAuditPage returns for unauthorised roles).
    - Seed 3 findings (phantom 2, 7, 25). Apply phantom_min=10 filter via Livewire request. Assert: only the 25-row finding is visible.
    - Assert "Resync selected to Woo" bulk action button is registered in the page's table (assert via Livewire ->assertTableBulkActionExists('resync_selected')).

    SnapshotAggregatorStockDivergenceTest (1 case):
    - Seed 2 findings (phantom 3 + 5). Call `app(SnapshotAggregator::class)->computeStockDivergence()`. Assert array equals `['count' => 2, 'total_phantom_units' => 8, 'last_run_at' => /* iso8601 string matching the max audited_at */]`.
  </behavior>
  <action>
    Use Pest 3 syntax (`it('...')` + `beforeEach`). Mirror existing patterns in `tests/Feature/Filament/CategoryAuditPageTest.php` for the role gates + Livewire interactions. Use the existing `Tests\TestCase` base + `RefreshDatabase` trait.

    For the engine tests, mock WooClient via `$this->instance(WooClient::class, Mockery::mock(...))` — pattern used in tests that exist for `BackfillCategoryFromWooCommand` (grep tests/ for `WooClient::class` mock examples). Capture command output via `$this->artisan('products:audit-stock-divergence')->expectsOutputToContain(...)`.

    DO NOT skip case E (the graceful-404 path) — it's the riskiest behavioural promise this plan makes.

    Atomic commit at end of task: `test(products,dashboard): audit-stock-divergence engine + page + aggregator coverage (260609-nku)`
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Console/AuditStockDivergenceCommandTest.php tests/Feature/Filament/StockDivergencePageTest.php tests/Unit/Domain/Dashboard/SnapshotAggregatorStockDivergenceTest.php 2>&amp;1</automated>
  </verify>
  <done>
    All 11 cases green (6 engine + 4 page + 1 aggregator); commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 7: Cross-reference hints between audit pages</name>
  <files>
    resources/views/filament/pages/category-audit.blade.php,
    resources/views/filament/pages/stock-divergence.blade.php
  </files>
  <action>
    Edit `resources/views/filament/pages/category-audit.blade.php` — locate the existing footer banner / hint block (from 260607-v5g backfill hint, per the spec). Add a second hint line BELOW the existing one:

    `See also <a href="/admin/stock-divergence" class="underline">/admin/stock-divergence</a> (260609-nku) for SKUs where Woo claims stock but no fresh supplier carries any.`

    Edit `resources/views/filament/pages/stock-divergence.blade.php` — locate the footer banner block (added in Task 4). Add a symmetric line:

    `See also <a href="/admin/category-audit" class="underline">/admin/category-audit</a> (260607-t6w) for taxonomy issues.`

    Use the same wrapper element / Tailwind classes the existing hint uses in category-audit.blade.php — don't invent new styling.

    Cross-links surface each audit from the other so future ecom mgrs find them without archaeology.

    Atomic commit at end of task: `feat(stock-divergence,category-audit): cross-reference hints between audit pages (260609-nku)`
  </action>
  <verify>
    <automated>findstr /C:"260609-nku" "resources\views\filament\pages\category-audit.blade.php" &amp;&amp; findstr /C:"260607-t6w" "resources\views\filament\pages\stock-divergence.blade.php"</automated>
  </verify>
  <done>
    Both blades contain the cross-reference quick-ID anchor; commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 8: Verify — full regression sweep (no commit)</name>
  <files></files>
  <action>
    Run the verification checks; do NOT commit anything in this task. Capture every failure for diagnosis before declaring done.

    1. Command resolution: `php artisan list | findstr audit-stock-divergence` — MUST print a line.
    2. Schedule entry: `php artisan schedule:list | findstr audit-stock-divergence` — MUST print Mon 09:15 Europe/London.
    3. Focused Pest suite (the 11 new cases):
       `php artisan test tests/Feature/Console/AuditStockDivergenceCommandTest.php tests/Feature/Filament/StockDivergencePageTest.php tests/Unit/Domain/Dashboard/SnapshotAggregatorStockDivergenceTest.php`
       — ALL GREEN.
    4. Regression filters (cases that touch the same surfaces):
       `php artisan test tests/Feature/Filament/CategoryAuditPageTest.php tests/Feature/Console/SupplierFreshnessResolverTest.php tests/Feature/Console/AdCandidateScannerTest.php tests/Feature/Console/CompetitorPositionScannerTest.php tests/Feature/Dashboard/HomeDashboardPageTest.php`
       — ALL GREEN. HomeDashboardPageTest's 15→16 widget bump is INTENTIONAL.
    5. Full Pest suite delta vs 260608-g8x baseline (1,953 / 222 / 3):
       `php artisan test 2>&amp;1 | findstr /R "Tests:.*Passed"`
       Expected: +N pass / 0 new fails. Tolerable: same pre-existing failures from baseline (the 3 reds inherited from 260608-g8x).
    6. Dry-run smoke against the live local environment:
       `php artisan products:audit-stock-divergence --dry-run --limit=10`
       — Runs without exception. WILL hit Woo for 10 real product ids. Capture counters table from output. Acceptable outcomes: any counter distribution (the only failure mode is a thrown exception).
    7. Manual UI smoke (local Filament):
       - Visit /admin → 16th widget visible (StockDivergenceWidget).
       - Visit /admin/stock-divergence → page renders; brand sort + phantom_min filter usable; "Resync selected to Woo" bulk action present in the bulk-action menu.
       - Visit /admin/category-audit → footer hint includes the new "See also /admin/stock-divergence" line.

    No commit on this task.
  </action>
  <verify>
    <automated>php artisan test 2>&amp;1 | findstr /R "Tests:.*Passed"</automated>
  </verify>
  <done>
    All 7 checks pass; full Pest suite shows +N pass / 0 new fails vs baseline; ready to mark quick task complete.
  </done>
</task>

</tasks>

<verification>
- `php artisan list | findstr audit-stock-divergence` resolves the new command.
- `php artisan schedule:list | findstr audit-stock-divergence` shows Mon 09:15 Europe/London.
- The 11 new Pest cases (6 engine + 4 page + 1 aggregator) are green.
- HomeDashboardPageTest, CategoryAuditPageTest, SupplierFreshnessResolverTest, AdCandidateScannerTest, CompetitorPositionScannerTest are green (widget-count bump intentional).
- Full Pest delta: +N pass / 0 new fails vs the 260608-g8x baseline (1,953 / 222 / 3).
- Dry-run smoke (`--dry-run --limit=10`) runs without exception against the real Woo endpoint.
- /admin renders 16 widgets; /admin/stock-divergence renders with bulk action visible; /admin/category-audit footer cross-references /admin/stock-divergence.
</verification>

<success_criteria>
- Operator can run `php artisan products:audit-stock-divergence` and `--dry-run` to detect phantom-stock SKUs without writing.
- Operator can visit /admin/stock-divergence and see divergent SKUs sorted by phantom_units DESC with bulk-resync capped at 100.
- /admin home dashboard surfaces divergence count + total phantom units in the new 16th widget.
- Notification centre `stale_data` bucket includes a `stock_divergence` entry linking to /admin/stock-divergence.
- Weekly Mon 09:15 London cron runs the audit unattended.
- Cross-references between /admin/category-audit and /admin/stock-divergence land in both blade footers.
- Fresh-supplier predicate stays a single source of truth in `SupplierFreshnessResolver` — no duplicated DATEDIFF/julianday SQL in the audit command.
- TRUNCATE-and-replace snapshot semantics consistent with 260607-t6w (category_audit_findings) and 260608-g8x (supplier_freshness_snapshots).
- 11 new Pest cases green; full suite shows 0 new regressions vs the 260608-g8x baseline.
- 7 atomic commits (Tasks 1–7) plus 1 verify task (Task 8, no commit).
</success_criteria>

<output>
On completion, create `.planning/quick/260609-nku-products-audit-stock-divergence-surface-/260609-nku-SUMMARY.md` covering:
- The 7 commits landed (one per Task 1–7).
- Migration name (with timestamp).
- Counters captured from the live `--dry-run --limit=10` smoke.
- Any deviation from this plan (path choices, filter scope changes, blade structure adjustments).
- The next-Mon-09:15-London-cron-fire ETA so the operator knows when the first live audit lands.
</output>
