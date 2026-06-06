---
quick_id: 260606-lhp
type: execute
mode: quick
wave: 1
depends_on: []
files_modified:
  - app/Domain/Suggestions/Models/Suggestion.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
  - app/Domain/Dashboard/Services/SnapshotAggregator.php
  - app/Filament/Widgets/HighConfidenceSourceableWidget.php
  - app/Filament/Widgets/SuggestionsQueueHealthWidget.php
  - app/Filament/Pages/HomeDashboardPage.php
  - app/Providers/Filament/AdminPanelProvider.php
  - tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php
  # PendingReviewsWidget DELETED iff it shows only the same NPO pending count
  # (it does — see Task 5 disposition). File removal is a delete, not modify.
autonomous: true
requirements:
  - LHP-01  # high-confidence sourceable opportunities tile
  - LHP-02  # decision queue health tile
  - LHP-03  # shared predicate (badge ↔ aggregator drift prevention)

must_haves:
  truths:
    - "Admin lands on /admin and sees a 'High-confidence sourceable opportunities' tile showing the same actionable count as the Suggestions sidebar badge"
    - "Admin clicks the tile and is taken to /admin/suggestions with kind=new_product_opportunity + status=pending + competitor_count_bucket=3plus + on_supplier_db=yes already applied"
    - "Admin sees a 'Decision queue health' tile showing Applied(7d), Rejected(7d), and Oldest pending age in days"
    - "The Decision queue health tile turns warning-colored when oldest pending > 30 days"
    - "Sales / read_only users do NOT see either tile (silent absence, not 403)"
    - "Tiles read from dashboard_snapshots (NOT live DB queries) — refreshed every 5 min by dashboard:refresh"
    - "The high_confidence_count value rendered by Tile 1 is byte-identical to SuggestionResource::getNavigationBadge() (drift-proof via shared Eloquent scope)"
  artifacts:
    - path: "tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php"
      provides: "Drift-prevention + payload-shape contract on computeSuggestionsTriageHealth()"
    - path: "app/Domain/Suggestions/Models/Suggestion.php"
      provides: "scopeHighConfidenceSourceable(Builder $q): Builder — driver-aware JSON predicate"
      contains: "scopeHighConfidenceSourceable"
    - path: "app/Domain/Dashboard/Services/SnapshotAggregator.php"
      provides: "computeSuggestionsTriageHealth(): array — 6-key payload, single DB round-trip per snapshot refresh"
      contains: "computeSuggestionsTriageHealth"
    - path: "app/Filament/Widgets/HighConfidenceSourceableWidget.php"
      provides: "Tile 1 — clickable stat with 3-tier breakdown, links to filtered suggestions table"
    - path: "app/Filament/Widgets/SuggestionsQueueHealthWidget.php"
      provides: "Tile 2 — applied/rejected 7d + oldest pending age"
  key_links:
    - from: "app/Filament/Widgets/HighConfidenceSourceableWidget.php"
      to: "dashboard_snapshots (metric_key=suggestions_triage_health)"
      via: "DashboardSnapshot::where('metric_key', ...)->first()->metric_value_json"
      pattern: "metric_key.*suggestions_triage_health"
    - from: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (getNavigationBadge)"
      to: "Suggestion::scopeHighConfidenceSourceable"
      via: "Suggestion::query()->highConfidenceSourceable()->count()"
      pattern: "highConfidenceSourceable"
    - from: "app/Domain/Dashboard/Services/SnapshotAggregator.php (computeSuggestionsTriageHealth)"
      to: "Suggestion::scopeHighConfidenceSourceable"
      via: "Suggestion::query()->highConfidenceSourceable()->count()"
      pattern: "highConfidenceSourceable"
    - from: "app/Domain/Dashboard/Services/SnapshotAggregator.php (computeAll)"
      to: "suggestions_triage_health metric_key"
      via: "registered in computeAll() so dashboard:refresh persists it"
      pattern: "suggestions_triage_health.*=>"
---

<objective>
Elevate the "high-confidence sourceable" insight from the Suggestions sidebar badge (shipped 260606-gnu / d853a27) to the Home dashboard as two decision-grade tiles, plumbed through the canonical SnapshotAggregator cache so they refresh every 5 min like every other tile and never run live DB queries on page load.

Tile 1 — "High-confidence sourceable opportunities": clickable stat that deep-links to /admin/suggestions with the 4 existing filters pre-applied (kind=new_product_opportunity, status=pending, competitor_count_bucket=3plus, on_supplier_db=yes). Description shows the 3-tier breakdown (high-confidence • sourceable • raw pending). Color success when count>=1, gray when 0.

Tile 2 — "Decision queue health": three stacked stats — Applied (7d), Rejected (7d), Oldest pending age (days). Color warning when oldest_pending_days > 30, else gray. No click-through (informational).

Plumbing — single SnapshotAggregator::computeSuggestionsTriageHealth(): array method returns the full 6-key payload (high_confidence_count, sourceable_count, raw_pending_count, applied_7d, rejected_7d, oldest_pending_days). One method, one DB round-trip per refresh, consumed by BOTH tiles. Drift-prevention: the high_confidence_count predicate lives ONCE on a Suggestion::scopeHighConfidenceSourceable Eloquent scope; both the sidebar badge AND the aggregator call the scope.

Retire PendingReviewsWidget IF it shows only the same NPO pending count (it does — see Task 5 disposition).

Purpose: today the dashboard's PendingReviewsWidget shows the same uninformative ~14k raw-pending count the sidebar badge already abandoned for noise reasons. Operators landing on /admin should see decision-grade signal — what's actionable now AND whether the queue is healthy — not a number that hides the 5,400 actionable rows under 8,800 competitor-only orphans.

Output: 2 new widgets + 1 new SnapshotAggregator method + 1 new Suggestion scope + 1 updated badge/tooltip pair (point at the scope) + 1 widget retired + 1 Pest test pinning the contract. Atomic commit per task. Full Pest suite stays green.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Dashboard/Services/SnapshotAggregator.php
@app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
@app/Domain/Suggestions/Models/Suggestion.php
@app/Domain/Suggestions/Console/Commands/PruneOrphanSuggestionsCommand.php
@app/Filament/Widgets/IntegrationHealthWidget.php
@app/Filament/Widgets/PendingReviewsWidget.php
@app/Filament/Pages/HomeDashboardPage.php
@app/Providers/Filament/AdminPanelProvider.php
@app/Domain/Dashboard/Models/DashboardSnapshot.php
@tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php
@tests/Feature/Dashboard/WidgetDataSourceTest.php

<interfaces>
<!-- Extracted from the codebase so the executor does NOT need a scavenger hunt. -->

# Suggestion model — current shape (app/Domain/Suggestions/Models/Suggestion.php)
class Suggestion extends Model {
    use HasUlids;
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'kind', 'status', 'correlation_id', 'payload', 'evidence',
        'proposed_by_type', 'proposed_by_id', 'proposed_at',
        'resolved_by_user_id', 'resolved_at', 'rejection_reason',
        'applied_at', 'agent_rejection_feedback',
    ];
    protected $casts = [
        'payload' => 'array', 'evidence' => 'array',
        'proposed_at' => 'datetime', 'resolved_at' => 'datetime',
        'applied_at' => 'datetime',
        'agent_rejection_feedback' => 'array',
    ];
}
# Task 2 ADDS: public function scopeHighConfidenceSourceable(Builder $q): Builder

# Current SuggestionResource badge — predicate the scope must match byte-for-byte
# (lines 65-79 of app/Domain/Suggestions/Filament/Resources/SuggestionResource.php):
$count = Suggestion::query()
    ->where('status', Suggestion::STATUS_PENDING)
    ->where('kind', 'new_product_opportunity')
    ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku')))))")
    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) >= 3")
    ->count();
# After Task 2: the inline whereRaw chain becomes Suggestion::query()->highConfidenceSourceable()->count()

# Driver-aware JSON pattern — copy from PruneOrphanSuggestionsCommand (proven on SQLite + MySQL):
# SQLite test path:    json_extract(evidence, '$.sku')                                          (returns unquoted string)
# MySQL prod path:     JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.sku'))                            (strip quotes)
# SQLite int cast:     CAST(json_extract(evidence, '$.supporting_competitors') AS INTEGER)
# MySQL int cast:      CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED)
# Use DB::connection()->getDriverName() === 'sqlite' to switch.

# SnapshotAggregator — service shape (app/Domain/Dashboard/Services/SnapshotAggregator.php)
final class SnapshotAggregator {
    public function computeAll(): array { /* map of metric_key => payload */ }
    public function read(string $metricKey): ?array;
    public function refreshAll(): int;
    public function computeLastSyncRun(): array;
    public function computeCrmPushSuccessRate(): array;
    public function computeCompetitorFreshness(): array;
    public function computePendingReviews(): array;
    public function computeImportIssues(): array;
    public function computeHorizonFailedJobs(): array;
    public function computeSyncDiffsParity(): array;
    public function computeProductCatalogueHealth(): array;
    public function computeWeeklyReportStatus(): array;
    public function computeIntegrationHealth(): array;
    # Task 3 ADDS: public function computeSuggestionsTriageHealth(): array
}
# Existing aggregator pattern — each method wraps DB reads in try/catch + Schema::hasTable
# guards for tables that may not exist in minimal test DBs. Mirror that style.
# Each new metric_key MUST be added to computeAll() so dashboard:refresh persists it.

# DashboardSnapshot::upsertByKey + read pattern (already used by every widget):
$snapshot = DashboardSnapshot::where('metric_key', 'suggestions_triage_health')->first();
$payload  = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

# Filament Stats widget shape (mirror IntegrationHealthWidget):
final class HighConfidenceSourceableWidget extends StatsOverviewWidget {
    protected static ?string $pollingInterval = null;
    protected int|string|array $columnSpan = 1;
    public static function canView(): bool {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }
    protected function getStats(): array { /* return [Stat::make(...)->color(...)->url(...)] */ }
}

# Existing filter parameter shapes — extracted from SuggestionResource::table() filters block:
#   SelectFilter::make('kind')                       → tableFilters[kind][value]=new_product_opportunity
#   SelectFilter::make('status')                     → tableFilters[status][value]=pending
#   SelectFilter::make('competitor_count_bucket')    → tableFilters[competitor_count_bucket][value]=3plus
#   SelectFilter::make('on_supplier_db')             → tableFilters[on_supplier_db][value]=yes
#
# Build the URL with route() + query string. Encode carefully — Filament parses
# tableFilters[kind][value] form-style. Verify in browser after deploy.

# PendingReviewsWidget — current shape (app/Filament/Widgets/PendingReviewsWidget.php):
#   Reads metric_key='pending_reviews' (NOT the new suggestions_triage_health).
#   Renders 3 Stats: 'Auto-create drafts' / 'Margin changes' / 'New product opportunities'.
#   The "New product opportunities" stat IS the raw-pending NPO count — the
#   redundant signal vs Tile 1. The "Auto-create drafts" + "Margin changes" stats
#   are DIFFERENT signals (different DB queries, different inbox kinds).
# Disposition (DECIDED — see Task 5):
#   PendingReviewsWidget shows MIXED signals (NPO is one of three). Tile 1
#   replaces only the NPO portion. We KEEP PendingReviewsWidget but REMOVE
#   the "New product opportunities" Stat so no duplicate signal appears.
#   (Alternative — full deletion — would silently retire the drafts +
#   margin-change signals, which are still valuable and have no replacement.)
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Pest test pins the aggregator payload + drift contract (RED)</name>
  <files>tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php</files>
  <behavior>
    - Test 1 — seeds the predicate matrix and asserts the 6-key payload from
      SnapshotAggregator::computeSuggestionsTriageHealth() matches the expected
      counts:
        Row A — pending NPO, evidence.sku='IN-CACHE-3C', supporting_competitors=3,
                proposed_at=now()->subDays(40). INSERT supplier_sku_cache row
                sku='in-cache-3c' (LOWER+TRIM applied at predicate time). Must
                be counted as high_confidence + sourceable + raw_pending.
        Row B — pending NPO, evidence.sku='IN-CACHE-1C', supporting_competitors=1,
                proposed_at=now()->subDays(5). INSERT supplier_sku_cache row
                sku='in-cache-1c'. Must be counted as sourceable + raw_pending
                but NOT high_confidence (only 1 competitor).
        Row C — pending NPO, evidence.sku='ORPHAN-3C', supporting_competitors=3,
                proposed_at=now()->subDays(20). NO supplier_sku_cache row. Must
                be counted as raw_pending only (no supplier match).
        Row D — pending margin_change (NOT new_product_opportunity). Must be
                excluded from all 3 counts (kind gate).
        Row E — applied NPO (status='applied'), resolved_at=now()->subDays(3).
                Must count toward applied_7d, NOT raw_pending.
        Row F — rejected NPO (status='rejected'), resolved_at=now()->subDays(2).
                Must count toward rejected_7d.
        Row G — applied NPO resolved_at=now()->subDays(10). Outside 7d window —
                must NOT count toward applied_7d.
      Expected payload:
        high_confidence_count  = 1   (Row A only)
        sourceable_count       = 2   (Rows A + B)
        raw_pending_count      = 3   (Rows A + B + C)
        applied_7d             = 1   (Row E)
        rejected_7d            = 1   (Row F)
        oldest_pending_days    = 40  (Row A — diffInDays floor)
    - Test 2 — drift-prevention: assert high_confidence_count equals
      `Suggestion::query()->highConfidenceSourceable()->count()`. Both pathways
      MUST agree; if a future refactor changes one but not the other this
      assertion bites.
    - Test 3 — empty-state safety: with zero Suggestion rows the payload returns
      all zeros and oldest_pending_days === null (rendered as "—" by the widget).
    - Test 4 — assert the payload includes all 6 expected keys exactly (no
      stray keys, no missing keys) — protects widget contract against silent
      schema drift.
    - Driver-aware JSON: rows are seeded via Suggestion::forceCreate with the
      'evidence' array cast — the predicate runs through SQLite json_extract
      in tests (and MySQL JSON_UNQUOTE in prod). Mirror the pattern from
      PruneOrphanSuggestionsCommand.
  </behavior>
  <action>
    Create tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php as the first
    commit of this plan — it MUST go RED before Tasks 2 and 3 land. Use
    RefreshDatabase, seed via Suggestion::forceCreate (mirror
    PruneOrphanSuggestionsCommandTest::makeOrphanSuggestion). For the
    supplier_sku_cache rows, use DB::table('supplier_sku_cache')->insert([...]) —
    the cache table has a single 'sku' PK column (lowercased; see 260604-szl
    memory). Each Suggestion gets 'evidence' as an array (cast handles JSON
    encoding) with shape {sku, supporting_competitors, competitor_sightings}.
    Use Carbon::setTestNow at the top of each test to lock 'now' so
    oldest_pending_days is deterministic. Resolve the aggregator via
    app(SnapshotAggregator::class) so the existing service container wiring
    is exercised.

    Verify RED locally before moving on: `vendor/bin/pest --filter=SuggestionsTriageWidget`
    MUST report the test class fails because computeSuggestionsTriageHealth() and
    the highConfidenceSourceable scope do not yet exist (BadMethodCallException
    or Error: Call to undefined method). If it errors with anything else (file
    not found, fatal in another test), stop and diagnose.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php --bail 2>&1 | grep -E "FAIL|ERROR|undefined" | head -20</automated>
  </verify>
  <done>
    Test file committed (atomic commit) and runs RED with errors referencing
    `computeSuggestionsTriageHealth` and/or `highConfidenceSourceable` being
    undefined. No other test files modified.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Shared Suggestion::scopeHighConfidenceSourceable + badge consumes the scope</name>
  <files>app/Domain/Suggestions/Models/Suggestion.php, app/Domain/Suggestions/Filament/Resources/SuggestionResource.php</files>
  <behavior>
    - After this task lands, `Suggestion::query()->highConfidenceSourceable()->count()`
      returns the same integer as the live SuggestionResource::getNavigationBadge()
      query does today on the prod DB (drift-prevention checkpoint in Task 1
      Test 2 must turn GREEN).
    - The scope applies all FOUR conditions in this exact order (matters for
      query plan + readability): status=pending, kind=new_product_opportunity,
      EXISTS supplier_sku_cache match on LOWER+TRIM(evidence.sku),
      CAST(supporting_competitors) >= 3.
    - Driver-aware JSON expression: SQLite uses json_extract; MySQL uses
      JSON_UNQUOTE(JSON_EXTRACT(...)) + CAST AS UNSIGNED. Use the same
      DB::connection()->getDriverName() === 'sqlite' switch pattern that
      PruneOrphanSuggestionsCommand already uses.
    - SuggestionResource::getNavigationBadge() and ::getNavigationBadgeTooltip()
      both delegate to the scope where applicable. The badge's defensive
      try/catch wrapper stays. The tooltip's three-tier breakdown still computes
      rawPending + sourceable + highConfidence; the highConfidence query
      becomes `Suggestion::query()->where('status', PENDING)->where('kind', NPO)
      ->highConfidenceSourceable()->count()` — but BECAUSE the scope already
      applies status + kind, the surrounding where() calls become redundant
      and must be REMOVED to avoid double-binding. Confirm this on the actual
      tooltip code path: the rebuilt tooltip should compute
        rawPending  = pending NPO count (NO scope)
        sourceable  = pending NPO + EXISTS supplier_sku_cache (NO scope — sourceable
                      is a DIFFERENT predicate; do not jam high-confidence in)
        highConf    = `Suggestion::query()->highConfidenceSourceable()->count()`
  </behavior>
  <action>
    Add `public function scopeHighConfidenceSourceable(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder` to app/Domain/Suggestions/Models/Suggestion.php. Inside the scope, branch on DB::connection()->getDriverName() exactly as PruneOrphanSuggestionsCommand does — extract two private static helper methods (or inline two ternaries) returning the SKU expression and the int-cast expression. Apply 4 where clauses: `where('status', self::STATUS_PENDING)`, `where('kind', 'new_product_opportunity')`, whereRaw EXISTS supplier_sku_cache match (LOWER+TRIM), whereRaw CAST >= 3. Return $q.

    Update SuggestionResource::getNavigationBadge() (lines ~65-79): replace the inline ->where + 2x whereRaw chain with `Suggestion::query()->highConfidenceSourceable()->count()`. Keep the try/catch + 'count > 0 ? string : null' return shape unchanged.

    Update SuggestionResource::getNavigationBadgeTooltip() (lines ~98-125): replace the highConfidence sub-query with `Suggestion::query()->highConfidenceSourceable()->count()` ONLY for the high-confidence number. The rawPending and sourceable computations stay (different predicates — the scope already includes the high-confidence supporting_competitors>=3 gate which sourceable does NOT). Keep Cache::remember 60s.

    Do NOT touch the `on_supplier_db` SelectFilter or the table filters block — they target different predicates (sourceable, not high-confidence).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php tests/Feature/Suggestions/PruneOrphanSuggestionsCommandTest.php 2>&1 | tail -30</automated>
  </verify>
  <done>
    - Suggestion::scopeHighConfidenceSourceable exists, is driver-aware, and is
      called from both getNavigationBadge() and getNavigationBadgeTooltip()
      (grep returns 3 hits: 1 definition + 2 call sites).
    - Task 1 Test 2 (drift-prevention) goes GREEN.
    - PruneOrphanSuggestionsCommand tests stay GREEN (regression check — the
      command shares the driver-aware JSON pattern).
    - Tests 1, 3, 4 from Task 1 still RED (aggregator method not yet added).
    - Atomic commit: `feat(suggestions): extract high-confidence scope + wire badge`.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: SnapshotAggregator::computeSuggestionsTriageHealth (GREEN)</name>
  <files>app/Domain/Dashboard/Services/SnapshotAggregator.php</files>
  <behavior>
    - New public method `computeSuggestionsTriageHealth(): array` returns a
      6-key payload:
        high_confidence_count: int   — uses ->highConfidenceSourceable() scope
        sourceable_count:      int   — pending NPO + EXISTS supplier_sku_cache
        raw_pending_count:     int   — pending NPO (no further gates)
        applied_7d:            int   — status='applied', kind='new_product_opportunity', resolved_at >= now()->subDays(7)
        rejected_7d:           int   — status='rejected', kind='new_product_opportunity', resolved_at >= now()->subDays(7)
        oldest_pending_days:   ?int  — diffInDays(oldest pending NPO proposed_at), null when no pending rows
    - The method is registered in computeAll() under metric_key
      'suggestions_triage_health' so dashboard:refresh persists it every 5 min.
    - Defensive try/catch returns zero-valued payload (with oldest_pending_days
      = null) on any Throwable — mirrors the existing aggregator pattern for
      missing tables / broken connection. The dashboard MUST NOT 500 if the
      supplier_sku_cache table is missing in a minimal test env (Schema::hasTable
      gate on the EXISTS sub-query).
    - DB read budget: 4 queries (high_confidence via scope COUNT; sourceable
      COUNT; raw_pending COUNT; applied_7d + rejected_7d collapsed into ONE
      selectRaw with CASE WHEN; oldest_pending via min('proposed_at') on the
      raw_pending subset). That's the single-DB-round-trip-per-snapshot-refresh
      target.
    - Cache::remember NOT needed at the aggregator layer — the whole point of
      dashboard_snapshots is to BE the cache. Widgets read from DashboardSnapshot,
      which is the cache layer. Adding Cache::remember inside the aggregator
      would double-cache and make refreshes silently stale.
  </behavior>
  <action>
    Add the method to app/Domain/Dashboard/Services/SnapshotAggregator.php immediately after computeIntegrationHealth(). Use the existing import for Suggestion (already at line 12). Wrap the body in try/catch (Throwable) — on exception, log via report($e) and return the zero-payload (high_confidence_count: 0, sourceable_count: 0, raw_pending_count: 0, applied_7d: 0, rejected_7d: 0, oldest_pending_days: null) so the dashboard never 500s.

    Use Schema::hasTable('supplier_sku_cache') around the sourceable + high_confidence reads — if the cache table is missing (test envs that skip the 260604-szl migration), return zero for both but continue computing the other 4 keys.

    For applied_7d + rejected_7d use ONE selectRaw on suggestions:
      DB::table('suggestions')
        ->where('kind', 'new_product_opportunity')
        ->whereIn('status', ['applied', 'rejected'])
        ->where('resolved_at', '>=', now()->subDays(7))
        ->selectRaw("SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied_7d, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_7d")
        ->first();

    For oldest_pending_days use:
      $oldest = Suggestion::query()
        ->where('kind', 'new_product_opportunity')
        ->where('status', Suggestion::STATUS_PENDING)
        ->min('proposed_at');
      $oldest_pending_days = $oldest === null ? null : (int) \Carbon\Carbon::parse($oldest)->diffInDays(now());

    Register in computeAll(): add `'suggestions_triage_health' => $this->computeSuggestionsTriageHealth(),` to the returned array (after 'integration_health'). This is what makes dashboard:refresh persist the snapshot every 5 min.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php tests/Feature/Dashboard/WidgetDataSourceTest.php tests/Feature/Dashboard/DashboardRefreshCommandTest.php 2>&1 | tail -40</automated>
  </verify>
  <done>
    - All 4 Task 1 tests go GREEN.
    - WidgetDataSourceTest + DashboardRefreshCommandTest stay GREEN (regression
      check — computeAll() shape changed).
    - Grep `metric_key.*suggestions_triage_health` returns 2+ hits
      (computeAll() registration + the new method's purpose docblock).
    - Atomic commit: `feat(dashboard): computeSuggestionsTriageHealth aggregator method`.
  </done>
</task>

<task type="auto">
  <name>Task 4: HighConfidenceSourceableWidget (Tile 1)</name>
  <files>app/Filament/Widgets/HighConfidenceSourceableWidget.php</files>
  <action>
    Create app/Filament/Widgets/HighConfidenceSourceableWidget.php extending Filament\Widgets\StatsOverviewWidget. Mirror IntegrationHealthWidget's structure: $pollingInterval = null, $columnSpan = 1, canView() returns hasAnyRole(['admin','pricing_manager']). In getStats(), read the snapshot via `DashboardSnapshot::where('metric_key', 'suggestions_triage_health')->first()`; cast metric_value_json to array; default to zeros if null.

    Build ONE Stat::make('High-confidence sourceable opportunities', number_format($highConfidence)) with:
      - ->description("{number_format(sourceable)} sourceable • {number_format(rawPending)} raw pending")
      - ->descriptionIcon('heroicon-m-sparkles')
      - ->color($highConfidence >= 1 ? 'success' : 'gray')
      - ->url(sprintf('/admin/suggestions?%s', http_build_query([
            'tableFilters' => [
                'kind' => ['value' => 'new_product_opportunity'],
                'status' => ['value' => 'pending'],
                'competitor_count_bucket' => ['value' => '3plus'],
                'on_supplier_db' => ['value' => 'yes'],
            ],
        ])))
      - Stale-ring extraAttributes when DashboardSnapshot::isStale() returns true
        (mirror PendingReviewsWidget lines 58-60 — `class' => 'ring-2 ring-amber-400`).

    Use http_build_query — it correctly encodes the nested tableFilters[kind][value]=new_product_opportunity form-style that Filament 3 parses on the suggestions index page. Verify the exact shape by reading SuggestionResource lines 230-308 (the four SelectFilter::make() definitions in this plan's context) — the keys above match the SelectFilter::make() names byte-for-byte.

    DO NOT use Cache::remember inside the widget — the snapshot is already the cache (per Task 3 note). Widget reads are bounded to one DB SELECT against dashboard_snapshots (~10 rows in that table).

    Discovery: the existing `->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')` line in AdminPanelProvider auto-discovers this class. The explicit panel-level ->widgets([...]) registration is needed (per Phase 7 Plan 02 docblock comments) — Task 5 handles that.
  </action>
  <verify>
    <automated>php -l app/Filament/Widgets/HighConfidenceSourceableWidget.php && vendor/bin/pest tests/Feature/Dashboard/HomeDashboardPageTest.php tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php 2>&1 | tail -20</automated>
  </verify>
  <done>
    - File compiles cleanly (php -l reports "No syntax errors").
    - canView() returns true for admin + pricing_manager, false for sales + read_only
      (assert via tinker or extend HomeDashboardPageTest if needed; not required as
      a new test since the canView() pattern is identical to IntegrationHealthWidget
      which is covered).
    - HomeDashboardPageTest stays GREEN (the existing 9-widget assertion will be
      updated in Task 5 — DO NOT register the widget yet).
    - Atomic commit: `feat(dashboard): HighConfidenceSourceableWidget`.
  </done>
</task>

<task type="auto">
  <name>Task 5: SuggestionsQueueHealthWidget + registration + PendingReviewsWidget surgery</name>
  <files>app/Filament/Widgets/SuggestionsQueueHealthWidget.php, app/Filament/Widgets/PendingReviewsWidget.php, app/Filament/Pages/HomeDashboardPage.php, app/Providers/Filament/AdminPanelProvider.php, tests/Feature/Dashboard/HomeDashboardPageTest.php</files>
  <action>
    Create app/Filament/Widgets/SuggestionsQueueHealthWidget.php extending Filament\Widgets\StatsOverviewWidget. Mirror IntegrationHealthWidget: $pollingInterval = null, $columnSpan = 1, canView() returns hasAnyRole(['admin','pricing_manager']).

    In getStats(), read DashboardSnapshot metric_key='suggestions_triage_health'. Extract applied_7d, rejected_7d, oldest_pending_days from the payload. Build 3 Stats stacked:
      Stat::make('Applied (7d)', number_format($applied))->color('success')->descriptionIcon('heroicon-m-check-circle')
      Stat::make('Rejected (7d)', number_format($rejected))->color('gray')->descriptionIcon('heroicon-m-x-circle')
      Stat::make('Oldest pending', $oldest === null ? '—' : sprintf('%d day%s', $oldest, $oldest === 1 ? '' : 's'))
        ->description('Days since the longest-waiting pending NPO row')
        ->descriptionIcon('heroicon-m-clock')
        ->color(($oldest !== null && $oldest > 30) ? 'warning' : 'gray')
    No ->url() — Tile 2 is informational only.

    Stale-ring extraAttributes when DashboardSnapshot::isStale() — same pattern as Tile 1.

    PendingReviewsWidget surgery — DELETE the "New product opportunities" Stat
    block (lines ~71-74 of app/Filament/Widgets/PendingReviewsWidget.php). KEEP
    the "Auto-create drafts" and "Margin changes" Stats (different signals, no
    replacement tile). Update the widget's class docblock to reflect the
    2-stat shape and reference this quick task (260606-lhp). Also DROP
    `new_product_opportunity_suggestions` from the locals it reads — the key
    stays in the snapshot payload (SnapshotAggregator::computePendingReviews
    still computes it; other consumers may exist), but PendingReviewsWidget
    no longer renders it.

    HomeDashboardPage::getWidgets() — INSERT both new widgets into the array.
    Place them right after PendingReviewsWidget (so they appear in the Row 2
    "Actions" group conceptually). Final order:
      LastSyncRunWidget, CrmPushSuccessRateWidget, CompetitorFreshnessWidget,
      PendingReviewsWidget, HighConfidenceSourceableWidget, SuggestionsQueueHealthWidget,
      ImportIssuesWidget, HorizonFailedJobsWidget,
      SyncDiffsParityWidget, ProductCatalogueHealthWidget, WeeklyReportStatusWidget,
      IntegrationHealthWidget
    Update getDashboardSections() — add both widgets to the "Needs attention"
    section's widgets array (alongside ImportIssuesWidget + PendingReviewsWidget).
    Bump that section's 'columns' from 2 to 2 still (the StatsOverviewWidget
    auto-flows) — but verify visually after deploy.

    AdminPanelProvider — register both new widgets in the ->widgets([...]) array
    so Filament resolves them through discovery + policy gates. Insert after
    PendingReviewsWidget::class.

    HomeDashboardPageTest — UPDATE the "exposes 9 widgets in
    HomeDashboardPage::getWidgets()" assertion: change toHaveCount(9) to
    toHaveCount(12) (Phase 09.1 added IntegrationHealthWidget making it 10;
    this plan adds 2 more = 12). Update the toBe([...]) array to match the
    new ordering. If the test currently only asserts 9, the IntegrationHealth
    widget was already added without updating the test — fix the count to
    the actual current array length + 2.

    DO NOT modify SnapshotAggregator::computePendingReviews() — keep the
    new_product_opportunity_suggestions key in the snapshot. Other code may
    consume it (grep first; if zero external consumers, leave it anyway —
    cheap to compute, no harm).
  </action>
  <verify>
    <automated>php -l app/Filament/Widgets/SuggestionsQueueHealthWidget.php && php -l app/Filament/Widgets/PendingReviewsWidget.php && php -l app/Filament/Pages/HomeDashboardPage.php && php -l app/Providers/Filament/AdminPanelProvider.php && vendor/bin/pest tests/Feature/Dashboard/ 2>&1 | tail -30</automated>
  </verify>
  <done>
    - All 4 modified PHP files compile.
    - SuggestionsTriageWidgetTest (Tasks 1+3) stays GREEN.
    - HomeDashboardPageTest GREEN with updated widget count + ordering.
    - All other tests/Feature/Dashboard/ tests stay GREEN.
    - Grep confirms PendingReviewsWidget no longer contains the string
      "New product opportunities" (the duplicate signal is gone).
    - Grep confirms both new widgets are referenced in HomeDashboardPage::getWidgets(),
      HomeDashboardPage::getDashboardSections(), AND AdminPanelProvider widgets array.
    - Atomic commit: `feat(dashboard): SuggestionsQueueHealthWidget + retire NPO duplicate signal`.
    - Run the full Pest suite as a final regression check: `vendor/bin/pest`
      and confirm zero NEW failures vs the 260606-gnu baseline (1,803 passed /
      223 pre-existing fails / 3 skipped per STATE.md). Capture the delta in
      the SUMMARY.md.
  </done>
</task>

</tasks>

<verification>
End-to-end gates (run after Task 5):

1. **Full Pest suite delta** — `vendor/bin/pest 2>&1 | tail -5` reports zero
   NEW failures vs the 260606-gnu baseline (1,803 passed / 223 pre-existing
   fails / 3 skipped). Capture exact pass/fail counts in the SUMMARY.

2. **Drift-prevention** — run `php artisan tinker --execute='
   echo "badge: " . app(\App\Domain\Suggestions\Filament\Resources\SuggestionResource::class)::getNavigationBadge() . PHP_EOL;
   echo "agg:   " . app(\App\Domain\Dashboard\Services\SnapshotAggregator::class)->computeSuggestionsTriageHealth()["high_confidence_count"] . PHP_EOL;
   '` and confirm the two values are byte-identical on the dev DB.

3. **Snapshot refresh end-to-end** — `php artisan dashboard:refresh && php artisan tinker --execute='print_r(\App\Domain\Dashboard\Models\DashboardSnapshot::where("metric_key", "suggestions_triage_health")->first()->metric_value_json);'`
   returns the 6-key array with non-null values. Confirms computeAll() picks
   up the new metric_key + upsertByKey persists it.

4. **Widget render** — `php artisan serve` + visit /admin as admin. Assert:
   - High-confidence tile renders with a number + green color (or 0/gray)
   - Clicking the tile takes you to /admin/suggestions with all 4 filters
     pre-applied (verify by inspecting the URL bar and the filter chips above
     the table)
   - Decision queue health tile renders 3 stacked stats
   - Sign in as a sales user; both tiles ARE GONE (silent absence)

5. **Static analysis** — `vendor/bin/phpstan analyse app/Filament/Widgets/HighConfidenceSourceableWidget.php app/Filament/Widgets/SuggestionsQueueHealthWidget.php app/Domain/Dashboard/Services/SnapshotAggregator.php app/Domain/Suggestions/Models/Suggestion.php` reports zero new errors at the current project level.

6. **Deptrac** — `vendor/bin/deptrac analyse --no-progress 2>&1 | tail -10` reports zero new violations. Suggestions → Dashboard is NOT a new arrow (the aggregator reads Suggestion, which is the existing Phase 7 Plan 02 boundary).

7. **Architectural env() guardrail** — `vendor/bin/pest tests/Architecture/EnvUsageTest.php` stays GREEN (no env() leakage in any new code).
</verification>

<success_criteria>
- [ ] tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php committed RED in Task 1, then GREEN after Task 3
- [ ] Suggestion::scopeHighConfidenceSourceable() exists and is the SOLE definition of the high-confidence predicate (grep finds 1 definition + 3 call sites: badge, tooltip, aggregator)
- [ ] SnapshotAggregator::computeSuggestionsTriageHealth() returns the 6-key payload + is registered in computeAll() under 'suggestions_triage_health'
- [ ] HighConfidenceSourceableWidget + SuggestionsQueueHealthWidget files exist, extend StatsOverviewWidget, gate via canView() to admin + pricing_manager
- [ ] HomeDashboardPage::getWidgets() lists both new widgets (count 12, not 9 or 10)
- [ ] AdminPanelProvider widgets array lists both new widgets
- [ ] PendingReviewsWidget no longer renders the "New product opportunities" Stat (grep confirms removal)
- [ ] Full Pest suite zero NEW failures vs 260606-gnu baseline
- [ ] Manual /admin smoke: Tile 1 click-through lands on a filtered Suggestions table with all 4 filter chips visible
- [ ] Manual sales-user smoke: both tiles absent (no 403, just hidden)
- [ ] 5 atomic commits, one per task, all green
</success_criteria>

<deploy_notes>
Cron + view caches need clearing on prod after deploy so the new metric_key persists immediately:
  php artisan config:cache && php artisan route:cache && php artisan view:clear
  php artisan dashboard:refresh   # populate suggestions_triage_health row immediately rather than waiting for the next 5-min schedule fire
  # Horizon supervisor doesn't need restart (no new jobs / listeners shipped)
</deploy_notes>

<output>
Create `.planning/quick/260606-lhp-dashboard-decision-grade-tiles-high-conf/260606-lhp-SUMMARY.md` when done — include the full-Pest pass/fail delta vs the 260606-gnu baseline (1,803 / 223 / 3) so future "did we break anything" lookups have a clean trail.
</output>
