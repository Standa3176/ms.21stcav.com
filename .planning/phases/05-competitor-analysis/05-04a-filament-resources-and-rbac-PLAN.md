---
phase: 05-competitor-analysis
plan: 04a
type: execute
wave: 4
depends_on:
  - "05-03"
files_modified:
  - app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php
  - app/Domain/Competitor/Filament/Resources/CompetitorPriceResource/Pages/ListCompetitorPrices.php
  - app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php
  - app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource/Pages/ListCompetitorIngestRuns.php
  - app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php
  - app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/ListCsvParseErrors.php
  - app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/EditCsvParseError.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php
  - database/seeders/RolePermissionSeeder.php
  - database/seeders/DatabaseSeeder.php
  - tests/Feature/Competitor/CompetitorResourcesAccessMatrixTest.php
  - tests/Feature/Competitor/MarginChangeSuggestionApproveActionTest.php
  - tests/Feature/Competitor/NewProductOpportunityApproveActionTest.php
  - tests/Feature/Competitor/AlertRecipientCompetitorToggleTest.php
  - tests/Feature/Competitor/ShieldRestorationProtocolTest.php
  - tests/Architecture/PolicyTemplateIntegrityTest.php
autonomous: true
requirements:
  - COMP-05
  - COMP-09

must_haves:
  truths:
    - "`php artisan shield:generate --all --panel=admin --no-interaction` runs, any auto-damaged policy files (matching `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/`) are restored from HEAD, and a final grep returns zero `{{ ` literals (Pitfall P5-F restoration verified)"
    - "3 new Filament Resources exist under `app/Domain/Competitor/Filament/Resources/`: CompetitorPriceResource (read-only list, canCreate/canEdit/canDelete return false), CompetitorIngestRunResource (read-only list, filterable by competitor + status), CsvParseErrorResource (list + edit form for resolved_at only; delete admin-only)"
    - "Gate::forUser(adminRole)->allows('viewAny', CompetitorPrice::class) === true AND Gate::forUser(readOnlyRole)->allows('viewAny', CompetitorPrice::class) === false AND Gate::forUser(pricingManagerRole)->allows('update', $csvParseError) === true (D-04 pricing_manager resolves parse errors)"
    - "SuggestionResource has a TableAction('approve_margin_change') visible only when kind='margin_change' AND status='pending'; modalDescription shows old/new margin delta pulled from evidence JSON (D-07 shape from 05-03); confirming dispatches ApplySuggestionJob (Phase 1 D-17 pattern)"
    - "SuggestionResource has a TableAction('approve_new_product_opportunity') visible only when kind='new_product_opportunity' AND status='pending'; supporting_competitors count rendered as a column/badge on the table row; approve fires the Phase 5 NewProductOpportunityApplier stub"
    - "AlertRecipientResource form has a Toggle::make('receives_competitor_alerts') placed AFTER receives_crm_alerts toggle; the toggle persists a boolean to the column added in 05-01"
    - "DatabaseSeeder upserts ops@meetingstore.co.uk with receives_competitor_alerts=true (idempotent via firstOrCreate + follow-up update) so existing seeded rows gain the flag on re-seed"
    - "RolePermissionSeeder LIKE patterns extended with Phase 5 resources: admin gets `%_competitor_price`, `%_competitor_price::%`, `%_competitor_csv_mapping`, `%_competitor_csv_mapping::%`, `%_competitor_ingest_run`, `%_competitor_ingest_run::%`, `%_csv_parse_error`, `%_csv_parse_error::%` (BOTH underscore AND `::` variants per Phase 2 Shield 3.9.10 lesson); pricing_manager gets view_any/view on all four + update_competitor_csv_mapping (D-04) + update_csv_parse_error (mark resolved); sales gets view_any/view on competitor_price + competitor_ingest_run ONLY; read_only gets NOTHING"
    - "PolicyTemplateIntegrityTest floor count bumped to include Phase 5's 5 Competitor policies (shipped in 05-01) — previous floor + 5"
    - "Seeder re-run is idempotent: running `php artisan db:seed --class=RolePermissionSeeder --force` twice does not create duplicate permission attachments"
    - "Covered by checker directive: CompetitorResource (CRUD for Competitor itself) is intentionally deferred per revision guidance — not shipped in 05-04a. D-01 filename-prefix auto-discovery (05-02) remains the primary competitor-row creation path. If CompetitorResource is needed pre-Phase-7, it will be added in a targeted follow-up plan."
  artifacts:
    - path: "app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php"
      provides: "Read-only price history browser with SKU search + competitor filter + recorded_at date range"
      min_lines: 50
    - path: "app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php"
      provides: "Read-only run log mirror of Phase 2 SyncRunResource shape"
      min_lines: 50
    - path: "app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php"
      provides: "Issue browser with issue_type filter + resolve-via-resolved_at-field form for admin+pricing_manager"
      min_lines: 60
    - path: "database/seeders/RolePermissionSeeder.php"
      provides: "Extended LIKE patterns — both `_resource` AND `::resource` variants for Shield 3.9.10 coverage"
      contains: "%_competitor_price"
    - path: "tests/Architecture/PolicyTemplateIntegrityTest.php"
      provides: "Floor count bumped +5; zero `{{ ` literal strings asserted across all app/**/Policies/ paths"
  key_links:
    - from: "database/seeders/RolePermissionSeeder.php"
      to: "Shield-generated competitor permissions"
      via: "LIKE pattern on permission name"
      pattern: "%_competitor_price"
    - from: "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php"
      to: "app/Domain/Suggestions/Jobs/ApplySuggestionJob.php"
      via: "Approve action dispatches ApplySuggestionJob"
      pattern: "ApplySuggestionJob::dispatch"
    - from: "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php"
      to: "alert_recipients.receives_competitor_alerts column"
      via: "Toggle::make form field"
      pattern: "receives_competitor_alerts"
---

<objective>
Ship the admin-facing RBAC surface: 3 Filament Resources for the Competitor domain (price history browser, ingest-run log, CSV parse-error browser), the SuggestionResource extensions that expose the two new kinds (margin_change + new_product_opportunity) with kind-specific Approve actions, the AlertRecipientResource toggle for receives_competitor_alerts, and — critically — the shield:generate destructive pass with the P5-F restoration protocol.

Purpose: After this plan, admins + pricing_manager + sales have role-appropriate read access to competitor data, and the two new SuggestionApplier kinds are approvable via Filament. This is the RBAC half of Phase 5's UI — Pages, Widgets, Charts, and the stale-feed command ship in 05-04b next wave.

Output: 3 Resources + SuggestionResource extension + AlertRecipientResource extension + RolePermissionSeeder extension + DatabaseSeeder update + 5 Pest feature tests + PolicyTemplateIntegrityTest floor bump. No checkpoints — autonomous.

Scope-boundary reminder (per revision guidance): CompetitorResource (CRUD of the `competitors` table itself) is NOT shipped here — the D-01 filename-prefix auto-discovery in 05-02 remains the primary creation path. Admins view/edit competitor rows post-discovery via Tinker or a targeted follow-up plan if required before Phase 7.
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

# Filament Resource patterns to mirror
@.planning/phases/02-supplier-sync/02-04-SUMMARY.md
@.planning/phases/04-bitrix24-crm-sync/04-04-SUMMARY.md
@app/Domain/Sync/Filament/Resources/SyncRunResource.php
@app/Domain/Sync/Filament/Resources/ImportIssueResource.php
@app/Domain/CRM/Filament/Resources/CrmPushLogResource.php

# Suggestion Resource extension pattern (Phase 4 added the Replay action)
@app/Domain/Suggestions/Filament/Resources/SuggestionResource.php

# Alerting extension pattern (Phase 4 added receives_crm_alerts toggle)
@app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php

# Shield restoration pattern
@.planning/phases/02-supplier-sync/02-05-SUMMARY.md
@.planning/phases/04-bitrix24-crm-sync/04-04-SUMMARY.md
@tests/Architecture/PolicyTemplateIntegrityTest.php
@database/seeders/RolePermissionSeeder.php

<interfaces>
<!-- Phase 1-4 admin interfaces this plan extends -->

From app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (Phase 1 Plan 04 + Phase 4 Replay pattern):
```php
// Existing Resource has table columns: id (ULID short), kind, status, created_at.
// Phase 4 added ->action('replay') visible only when kind='crm_push_failed'.
// Phase 5 Plan 04a adds kind-specific actions for 'margin_change' + 'new_product_opportunity'.
```

From app/Domain/Suggestions/Jobs/ApplySuggestionJob.php (Phase 1 D-17):
```php
ApplySuggestionJob::dispatch($suggestion); // resolves kind → Applier → calls apply()
```

From RolePermissionSeeder (Phase 1 Plan 02 established pattern):
```php
// Uses LIKE patterns against Shield-generated permission names:
// e.g. Permission::where('name', 'LIKE', '%_product')->get()
// Underscore AND :: variants supported (Phase 2 Shield 3.9.10 lesson)
```

From app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php (Phase 4 Plan 03 pattern):
```php
// Existing form has toggles: receives_sync_reports, receives_crm_alerts.
// Phase 5 appends receives_competitor_alerts toggle after receives_crm_alerts.
```

From 05-03 D-07 evidence JSON (FROZEN contract):
```json
{
  "our_current_margin_bps": 5000,
  "proposed_margin_bps": 7000,
  "margin_delta_bps": 2000,
  "sku": "SKU-1",
  "competitor_name": "Acme",
  "pricing_rule": {"id": 5, "name": "...", "scope": "..."}
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: 3 Filament Resources + shield:generate + restoration protocol + seeder + PolicyTemplateIntegrityTest bump</name>
  <files>
    app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php,
    app/Domain/Competitor/Filament/Resources/CompetitorPriceResource/Pages/ListCompetitorPrices.php,
    app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php,
    app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource/Pages/ListCompetitorIngestRuns.php,
    app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php,
    app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/ListCsvParseErrors.php,
    app/Domain/Competitor/Filament/Resources/CsvParseErrorResource/Pages/EditCsvParseError.php,
    database/seeders/RolePermissionSeeder.php,
    database/seeders/DatabaseSeeder.php,
    tests/Architecture/PolicyTemplateIntegrityTest.php,
    tests/Feature/Competitor/CompetitorResourcesAccessMatrixTest.php,
    tests/Feature/Competitor/ShieldRestorationProtocolTest.php
  </files>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §Pitfall P5-F (full restoration playbook)
    - @.planning/phases/04-bitrix24-crm-sync/04-04-SUMMARY.md (most recent P5-F protocol execution log)
    - @.planning/phases/02-supplier-sync/02-05-SUMMARY.md (PolicyTemplateIntegrityTest shape)
    - @app/Domain/CRM/Filament/Resources/CrmPushLogResource.php (most recent read-only Resource reference)
    - @app/Domain/Sync/Filament/Resources/SyncRunResource.php (SyncRun → CompetitorIngestRun parallels)
    - @app/Domain/Sync/Filament/Resources/ImportIssueResource.php (ImportIssue → CsvParseError parallels)
    - @tests/Architecture/PolicyTemplateIntegrityTest.php (current policy count; floor will bump +5)
    - @database/seeders/RolePermissionSeeder.php (current LIKE patterns — Phase 1-4 entries to preserve)
  </read_first>
  <acceptance_criteria>
    - 3 Resources under `app/Domain/Competitor/Filament/Resources/` exist with correct read-only / edit semantics per spec below
    - `php artisan shield:generate --all --panel=admin --no-interaction` runs successfully
    - After shield:generate: `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` returns zero matches (restoration complete)
    - `PolicyTemplateIntegrityTest` passes with bumped policy count floor (previous count + 5)
    - `RolePermissionSeeder` runs idempotently — admin gets ALL permissions matching Phase 5 LIKE patterns (underscore + `::` variants); pricing_manager gets view/update on csv_parse_error + csv_mapping (D-04); sales gets view_any/view on competitor_price + competitor_ingest_run only; read_only gets nothing
    - `php artisan db:seed --class=RolePermissionSeeder --force` completes without error
    - Pest: admin viewAny on all 3 resources; pricing_manager viewAny + update csv_parse_error; sales viewAny on competitor_price + competitor_ingest_run ONLY; read_only cannot access any Competitor resources
    - DatabaseSeeder: ops@meetingstore.co.uk row updated to receives_competitor_alerts=true; idempotent re-run does not create duplicates
  </acceptance_criteria>
  <action>
**Step 1: Pre-flight policy hashes**
```bash
mkdir -p .tmp
sha256sum app/Policies/*.php app/Domain/*/Policies/*.php > .tmp/policy-hashes-pre.txt 2>/dev/null || true
```

**Step 2: Write 3 Filament Resources**

**`CompetitorPriceResource.php`** (read-only):
- `$navigationGroup = 'Competitor Intelligence'`
- `$navigationIcon = 'heroicon-o-currency-pound'`
- NO form. Override: `canCreate() => false; canEdit() => false; canDelete() => false`
- Table columns: sku (searchable), competitor.name (relationship column), price_pennies_gross (money GBP), price_pennies_ex_vat (money GBP), recorded_at (datetime sortable desc default)
- Filters: SelectFilter on competitor_id, DateRangeFilter or Tables\Filters\Filter on recorded_at
- Pages: only ListCompetitorPrices

**`CompetitorIngestRunResource.php`** (read-only; mirrors SyncRunResource):
- Navigation group: 'Competitor Intelligence'; icon 'heroicon-o-clipboard-document-list'
- NO form. Read-only
- Table columns: id, competitor.name, filename, rows_total, rows_written, rows_errored, rows_orphaned, status (BadgeColumn started=warning/completed=success/failed=danger), started_at, completed_at
- Filters: SelectFilter on status (3 options), SelectFilter on competitor_id
- Action column: view (shows full run details, linked parseErrors count via relationship count badge)
- Pages: only ListCompetitorIngestRuns

**`CsvParseErrorResource.php`**:
- Navigation group: 'Competitor Intelligence'; icon 'heroicon-o-exclamation-triangle'
- Form (edit-only): all fields disabled EXCEPT `resolved_at` (DateTimePicker, optional) — admins + pricing_manager mark rows resolved
- Table columns: issue_type (BadgeColumn), filename, line_number, competitor.name, created_at, resolved_at
- Filters: SelectFilter on issue_type (6 options from 05-01 enum), filter 'resolved' vs 'unresolved' via `->query(fn ($q, $v) => $q->whereNotNull('resolved_at'))`
- Actions: view (always), edit (update resolved_at — pricing_manager+admin via policy), delete (admin only)
- Pages: ListCsvParseErrors, EditCsvParseError

**Step 3: Run shield:generate**
```bash
php artisan shield:generate --all --panel=admin --no-interaction
```

**Step 4: Detect + restore damaged policies (P5-F protocol)**
```bash
# Detect policies Shield 3.9.10 damaged with {{ Placeholder }} literals
grep -rln '{{ ' app/Policies/ app/Domain/*/Policies/ 2>/dev/null > .tmp/damaged-policies.txt

# Restore each damaged policy from HEAD (they existed before shield:generate — hand-written in 05-01 + Phase 1-4)
while IFS= read -r FILE; do
  [ -n "$FILE" ] && git checkout HEAD -- "$FILE"
done < .tmp/damaged-policies.txt

# Re-verify: zero damaged
DAMAGED=$(grep -rln '{{ ' app/Policies/ app/Domain/*/Policies/ 2>/dev/null | wc -l)
if [ "$DAMAGED" != "0" ]; then
  echo "ABORT: ${DAMAGED} policies still damaged after restoration"
  exit 1
fi
echo "Restoration clean"
```

The 5 Competitor policies hand-written in 05-01 exist in HEAD (they were committed in that plan's commit). Phase 1-4 policies also exist in HEAD. If shield:generate overwrites any, `git checkout HEAD --` restores the hand-written version. If a policy DIDN'T exist in HEAD pre-shield (unlikely for our scope), the file can be regenerated from the hand-written 05-01 templates.

**Step 5: Extend RolePermissionSeeder**

APPEND to existing LIKE patterns arrays (preserve Phase 1-4 entries — use `array_merge` to avoid clobbering):

```php
// Phase 5 Competitor permissions — underscore + :: variants (Shield 3.9.10 coverage)
$adminLikes = array_merge($adminLikes, [
    '%_competitor_price', '%_competitor_price::%',
    '%_competitor_csv_mapping', '%_competitor_csv_mapping::%',
    '%_competitor_ingest_run', '%_competitor_ingest_run::%',
    '%_csv_parse_error', '%_csv_parse_error::%',
]);

$pricingManagerLikes = array_merge($pricingManagerLikes, [
    'view_any_competitor_price', 'view_competitor_price',
    'view_any_competitor_csv_mapping', 'view_competitor_csv_mapping', 'update_competitor_csv_mapping', // D-04
    'view_any_competitor_ingest_run', 'view_competitor_ingest_run',
    'view_any_csv_parse_error', 'view_csv_parse_error', 'update_csv_parse_error',
]);

$salesLikes = array_merge($salesLikes, [
    'view_any_competitor_price', 'view_competitor_price',
    'view_any_competitor_ingest_run', 'view_competitor_ingest_run',
]);

// read_only: explicitly NO competitor access
```

Idempotency is inherited from the Phase 1 pattern (`syncPermissions` or `givePermissionTo` with set semantics).

**Step 6: Update DatabaseSeeder for AlertRecipient competitor default**
```php
AlertRecipient::firstOrCreate(
    ['email' => 'ops@meetingstore.co.uk'],
    ['name' => 'Ops', 'is_active' => true, 'receives_sync_reports' => true, 'receives_crm_alerts' => true, 'receives_competitor_alerts' => true]
);
// If row existed pre-Phase-5, promote the flag:
AlertRecipient::where('email', 'ops@meetingstore.co.uk')->update(['receives_competitor_alerts' => true]);
```

**Step 7: Update PolicyTemplateIntegrityTest floor count**
If the test asserts `expect($policyCount)->toBeGreaterThanOrEqual(N)`, bump N by 5 (Phase 5's 5 Competitor policies from 05-01). Also re-assert the zero-`{{ ` invariant across all Policies paths.

**Step 8: Pest test** `tests/Feature/Competitor/CompetitorResourcesAccessMatrixTest.php`:
- Admin: viewAny + view on all 3 Resources; can update CsvParseError; can delete CsvParseError
- pricing_manager: viewAny + view all 3; can update CsvParseError (D-04); cannot delete
- sales: viewAny + view on CompetitorPrice + CompetitorIngestRun ONLY; CsvParseError denied
- read_only: zero Competitor resource access

**Step 9: `ShieldRestorationProtocolTest.php`**:
- Asserts `grep -rln '{{ ' app/Policies/ app/Domain/*/Policies/` returns empty at test time (guardrail that re-running shield:generate without the restoration step would fail this test).
  </action>
  <verify>
    <automated>php artisan db:seed --class=RolePermissionSeeder --force && php vendor/bin/pest tests/Feature/Competitor/CompetitorResourcesAccessMatrixTest.php tests/Feature/Competitor/ShieldRestorationProtocolTest.php tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure</automated>
  </verify>
  <done>3 Resources exist + navigate under 'Competitor Intelligence' group; shield:generate --all ran + restoration protocol verified (zero `{{ ` grep hits); PolicyTemplateIntegrityTest green with bumped floor; RolePermissionSeeder idempotently attaches new permissions; role permission matrix verified via Pest; ops@meetingstore.co.uk seed row has receives_competitor_alerts=true.</done>
</task>

<task type="auto">
  <name>Task 2: SuggestionResource kind-specific Approve actions + AlertRecipientResource competitor toggle</name>
  <files>
    app/Domain/Suggestions/Filament/Resources/SuggestionResource.php,
    app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php,
    tests/Feature/Competitor/MarginChangeSuggestionApproveActionTest.php,
    tests/Feature/Competitor/NewProductOpportunityApproveActionTest.php,
    tests/Feature/Competitor/AlertRecipientCompetitorToggleTest.php
  </files>
  <read_first>
    - @app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (Phase 4 added Replay action for crm_push_failed — mirror that visibility pattern)
    - @app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php (current toggles — add after receives_crm_alerts)
    - @.planning/phases/05-competitor-analysis/05-03-SUMMARY.md (D-07 evidence JSON shape — FROZEN; Approve modal renders from this)
    - @app/Domain/Suggestions/Jobs/ApplySuggestionJob.php (Phase 1 D-17 dispatch pattern)
    - @app/Domain/Suggestions/Services/SuggestionApplierResolver.php (confirm both 'margin_change' + 'new_product_opportunity' kinds registered pre-execution of this plan)
  </read_first>
  <acceptance_criteria>
    - SuggestionResource: when kind='margin_change', Approve action visible, tooltip/modalDescription shows old margin → new margin + delta (from evidence JSON D-07 shape); confirming dispatches ApplySuggestionJob(Suggestion); status transitions pending → applied via the applier chain
    - SuggestionResource: when kind='new_product_opportunity', Approve action visible + supporting_competitors badge column renders (uses evidence.supporting_competitors); approve runs the Phase 5 stub applier (logs "Phase 6 will wire supplier-request-list integration")
    - Reject action visible for both kinds when status=pending → writes status=rejected + reason text
    - AlertRecipientResource form has Toggle::make('receives_competitor_alerts') AFTER receives_crm_alerts with label 'Receives Competitor Alerts'
    - AlertRecipientCompetitorToggleTest: saving an AlertRecipient with toggle on persists the column to true
    - Pest: admin approving a seeded margin_change suggestion → Queue::assertPushed(ApplySuggestionJob); record's status updates through the applier (observed after job sync-runs in test)
    - Pest: admin approving a seeded new_product_opportunity suggestion → stub applier runs; result array returned with phase_5_stub=true; suggestion status=applied
  </acceptance_criteria>
  <action>
**SuggestionResource extensions** (`app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`):

Inside `table()` method, APPEND to the `->actions([...])` array. Use Filament v3 `Tables\Actions\Action` visibility pattern:

```php
Tables\Actions\Action::make('approve_margin_change')
    ->label('Approve')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->visible(fn (Suggestion $record) => $record->kind === 'margin_change' && $record->status === 'pending')
    ->authorize(fn (Suggestion $record) => auth()->user()?->can('approve', $record) ?? false)
    ->modalHeading('Approve Margin Change')
    ->modalDescription(function (Suggestion $record) {
        $old = (int) data_get($record->evidence, 'our_current_margin_bps', 0);
        $new = (int) data_get($record->evidence, 'proposed_margin_bps', 0);
        $delta = (int) data_get($record->evidence, 'margin_delta_bps', 0);
        return sprintf('Margin: %d bps → %d bps (Δ %d bps). Approving updates the PricingRule and fires PricingRuleChanged → recompute chain.', $old, $new, $delta);
    })
    ->requiresConfirmation()
    ->action(function (Suggestion $record) {
        \App\Domain\Suggestions\Jobs\ApplySuggestionJob::dispatch($record);
    }),

Tables\Actions\Action::make('approve_new_product_opportunity')
    ->label('Approve')
    ->icon('heroicon-o-plus-circle')
    ->color('primary')
    ->visible(fn (Suggestion $record) => $record->kind === 'new_product_opportunity' && $record->status === 'pending')
    ->authorize(fn (Suggestion $record) => auth()->user()?->can('approve', $record) ?? false)
    ->modalDescription(function (Suggestion $record) {
        $supporting = (int) data_get($record->evidence, 'supporting_competitors', 1);
        $sku = (string) data_get($record->evidence, 'sku', '?');
        return sprintf('SKU %s tracked by %d competitor(s). Phase 5 applier is a stub; Phase 6 will wire supplier-request-list.', $sku, $supporting);
    })
    ->requiresConfirmation()
    ->action(function (Suggestion $record) {
        \App\Domain\Suggestions\Jobs\ApplySuggestionJob::dispatch($record);
    }),

Tables\Actions\Action::make('reject')
    ->label('Reject')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn (Suggestion $record) => in_array($record->kind, ['margin_change', 'new_product_opportunity'], true) && $record->status === 'pending')
    ->authorize(fn (Suggestion $record) => auth()->user()?->can('approve', $record) ?? false)
    ->form([
        Forms\Components\Textarea::make('reason')->label('Rejection reason')->required()->maxLength(500),
    ])
    ->action(function (Suggestion $record, array $data) {
        $record->update(['status' => 'rejected', 'applier_result' => ['rejected_reason' => $data['reason']]]);
    }),
```

Add a `Tables\Columns\TextColumn::make('supporting_competitors')` visible only for new_product_opportunity kind:
```php
Tables\Columns\TextColumn::make('evidence.supporting_competitors')
    ->label('Competitors')
    ->badge()
    ->color('info')
    ->visible(fn ($livewire) => /* always show; empty for other kinds */ true)
    ->formatStateUsing(fn ($state) => $state !== null ? $state : '—'),
```

**AlertRecipientResource extension**:

In the form definition, APPEND after the receives_crm_alerts toggle:
```php
Forms\Components\Toggle::make('receives_competitor_alerts')
    ->label('Receives Competitor Alerts')
    ->inline(false)
    ->helperText('Receive stale-feed warnings + competitor ingest failure notifications.'),
```

Also add a table column:
```php
Tables\Columns\IconColumn::make('receives_competitor_alerts')->boolean()->label('Comp'),
```

**Tests**:

- `MarginChangeSuggestionApproveActionTest` — Livewire action test:
  ```php
  // Seed: admin user, pending margin_change suggestion with D-07 evidence + payload
  Queue::fake();
  Livewire::actingAs($admin)
      ->test(\App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions::class)
      ->callTableAction('approve_margin_change', $suggestion)
      ->assertHasNoTableActionErrors();
  Queue::assertPushed(\App\Domain\Suggestions\Jobs\ApplySuggestionJob::class);
  ```

- `NewProductOpportunityApproveActionTest` — same shape but kind='new_product_opportunity'; asserts stub applier Log::info emitted (`Log::shouldReceive('info')->with('new_product_opportunity.stub_applied', anything())`)

- `AlertRecipientCompetitorToggleTest`:
  ```php
  Livewire::actingAs($admin)
      ->test(\App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages\CreateAlertRecipient::class)
      ->fillForm(['email' => 'new@y.z', 'name' => 'x', 'is_active' => true, 'receives_competitor_alerts' => true])
      ->call('create')
      ->assertHasNoFormErrors();
  $this->assertDatabaseHas('alert_recipients', ['email' => 'new@y.z', 'receives_competitor_alerts' => true]);
  ```
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/MarginChangeSuggestionApproveActionTest.php tests/Feature/Competitor/NewProductOpportunityApproveActionTest.php tests/Feature/Competitor/AlertRecipientCompetitorToggleTest.php --stop-on-failure</automated>
  </verify>
  <done>SuggestionResource renders kind-specific Approve actions for both new kinds + Reject action with reason textarea; AlertRecipientResource form has receives_competitor_alerts toggle persisting to DB; 3 Pest feature tests green; approving a margin_change suggestion dispatches ApplySuggestionJob; approving a new_product_opportunity suggestion runs the Phase 5 stub applier.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Filament HTTP → Policy check | Every Resource action gated by Shield permission via `->authorize()` |
| Admin Approve on margin_change → PricingRule update | Trusted admin; audit trail via MarginChangeApplier (shipped 05-03) records before/after |
| shield:generate execution | Destructive on policy files; restoration protocol mitigates |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-04a-01 | Tampering | shield:generate re-damaging policies | mitigate | Restoration protocol (Pitfall P5-F); PolicyTemplateIntegrityTest permanent guardrail; Step 4 git checkout HEAD on every `{{ `-contaminated file. |
| T-05-04a-02 | Information Disclosure | read_only seeing competitor data | mitigate | RolePermissionSeeder explicitly omits competitor patterns for read_only role; verified via CompetitorResourcesAccessMatrixTest Pest. |
| T-05-04a-03 | Elevation of Privilege | Non-admin approving margin_change | mitigate | `->authorize(fn => can('approve', $record))` on the Filament Action + SuggestionPolicy (Phase 1 D-15) gates approve to admin role only. |
| T-05-04a-04 | Tampering | Reject reason field injection | mitigate | Textarea maxLength 500; Eloquent parameter binding; stored in `applier_result` JSON — no template rendering of untrusted content in any subsequent read path. |
| T-05-04a-05 | Information Disclosure | Suggestion.evidence.pricing_rule.scope admin-viewable | accept | Admin-only Filament surface; commercial data but not PII. Phase 1 D-05 365-day retention on audit_log applies. |
</threat_model>

<verification>
- All 5 Pest test files in this plan green
- PolicyTemplateIntegrityTest green with bumped floor
- `grep -rn "{{ " app/Policies/ app/Domain/*/Policies/` returns zero matches post-shield:generate
- Role matrix sanity passes for admin / pricing_manager / sales / read_only
- No Phase 1-4 test regressions (`php vendor/bin/pest --exclude-group competitor` still green)
</verification>

<success_criteria>
- 3 Filament Resources shipped (CompetitorPrice + CompetitorIngestRun + CsvParseError), navigable under 'Competitor Intelligence' group
- Shield permissions regenerated cleanly; hand-written Policies survive the restoration protocol
- PolicyTemplateIntegrityTest passes with bumped floor count (+5)
- RolePermissionSeeder extended + idempotent
- SuggestionResource supports approving both margin_change + new_product_opportunity; Reject action present for both kinds
- AlertRecipientResource toggle persists receives_competitor_alerts
- DatabaseSeeder promotes ops@meetingstore.co.uk to receive competitor alerts
</success_criteria>

<output>
Create `.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md` documenting:
- Policies restored by the shield:generate protocol (list of paths) + how many `{{ ` literals were found and reverted
- Final PolicyTemplateIntegrityTest count (previous + 5)
- Roles × Resources permission matrix FROZEN for 05-04b + Phase 7 dashboard consumption
- Any Filament v3 TableAction visibility quirks discovered with kind-specific rendering
- Whether `supporting_competitors` column renders correctly via JSON-path TextColumn or required a custom accessor
- Note explicitly: CompetitorResource (CRUD for Competitor table) NOT shipped per revision guidance; D-01 auto-discovery remains primary creation path
</output>
