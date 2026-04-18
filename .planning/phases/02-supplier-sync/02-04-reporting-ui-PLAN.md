---
phase: 02-supplier-sync
plan: 04
type: execute
wave: 4
depends_on:
  - 02-01
  - 02-03
files_modified:
  - database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php
  - app/Domain/Alerting/Models/AlertRecipient.php
  - app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php
  - app/Domain/Sync/Mail/SupplierSyncReportMail.php
  - app/Domain/Sync/Reports/SyncReportCsvGenerator.php
  - app/Domain/Sync/Commands/SyncSupplierCommand.php
  - app/Domain/Sync/Filament/Resources/SyncRunResource.php
  - app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ListSyncRuns.php
  - app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ViewSyncRun.php
  - app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncErrorsRelationManager.php
  - app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncRunItemsRelationManager.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/ListImportIssues.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/EditImportIssue.php
  - app/Domain/Products/Filament/Resources/ProductResource.php
  - app/Domain/Products/Filament/Resources/ProductResource/Pages/ListProducts.php
  - app/Domain/Products/Filament/Resources/ProductResource/Pages/ViewProduct.php
  - app/Domain/Products/Filament/Resources/ProductResource/Pages/EditProduct.php
  - app/Domain/Products/Filament/Resources/ProductResource/RelationManagers/VariantsRelationManager.php
  - app/Providers/Filament/AdminPanelProvider.php
  - database/seeders/RolePermissionSeeder.php
  - resources/views/emails/supplier-sync-report.blade.php
  - tests/Feature/ReceivesSyncReportsColumnTest.php
  - tests/Feature/SyncReportCsvGeneratorTest.php
  - tests/Feature/SyncReportMailTest.php
  - tests/Feature/SyncRunResourceTest.php
  - tests/Feature/ImportIssueResourceTest.php
  - tests/Feature/ProductResourceTest.php
autonomous: true
requirements:
  - SYNC-08
  - SYNC-11
  - SYNC-12

must_haves:
  truths:
    - "alert_recipients has a nullable boolean `receives_sync_reports` column with default TRUE (D-08)"
    - "A completed or aborted SyncRun produces a CSV with the exact 11 columns in D-10 order, one row per touched SKU"
    - "SupplierSyncReportMail emails the CSV to every active alert_recipients row where receives_sync_reports=true"
    - "Filament /admin/sync-runs lists runs with duration, status, counts; drill-down shows errors + run items"
    - "Filament /admin/import-issues lists 4 issue types with filters; pricing_manager can resolve"
    - "Filament /admin/products lists Product + variants; pricing_manager can edit price/cost fields"
    - "Shield regeneration does not leak {{ Placeholder }} literals; hand-edited policies from P01 survive"
  artifacts:
    - path: "database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php"
      provides: "ADD COLUMN receives_sync_reports BOOLEAN NULL DEFAULT 1"
    - path: "app/Domain/Alerting/Models/AlertRecipient.php"
      provides: "Added `receives_sync_reports` to $fillable + cast + scopeReceivesSyncReports"
    - path: "app/Domain/Sync/Reports/SyncReportCsvGenerator.php"
      provides: "generate(SyncRun): string — writes 11-column CSV via spatie/simple-excel; returns storage path"
    - path: "app/Domain/Sync/Mail/SupplierSyncReportMail.php"
      provides: "Mailable with subject [ABORTED]/[SUCCESS] + view + attach(csvPath)"
    - path: "app/Domain/Sync/Filament/Resources/SyncRunResource.php"
      provides: "SYNC-11 — table with badges/filters + drill-down Pages + 2 RelationManagers"
    - path: "app/Domain/Sync/Filament/Resources/ImportIssueResource.php"
      provides: "SYNC-12 — table with issue_type filter + resolve bulk action"
    - path: "app/Domain/Products/Filament/Resources/ProductResource.php"
      provides: "Product + ProductVariant listing/edit for pricing_manager (D-01 expansion)"
  key_links:
    - from: "app/Domain/Sync/Mail/SupplierSyncReportMail.php"
      to: "app/Domain/Alerting/Models/AlertRecipient.php"
      via: "Mail::to(AlertRecipient::active()->receivesSyncReports()->get())"
      pattern: "receivesSyncReports"
    - from: "app/Domain/Sync/Commands/SyncSupplierCommand.php"
      to: "app/Domain/Sync/Reports/SyncReportCsvGenerator.php + SupplierSyncReportMail"
      via: "after finalise() or abort() → generator->generate($run) → Mail::to(...)->send"
      pattern: "SyncReportCsvGenerator|SupplierSyncReportMail"
    - from: "app/Domain/Sync/Filament/Resources/SyncRunResource.php"
      to: "app/Domain/Sync/Policies/SyncRunPolicy.php (P01)"
      via: "Shield discovers + ProductResource inherits admin-gated policy"
      pattern: "SyncRunPolicy"
    - from: "app/Providers/Filament/AdminPanelProvider.php"
      to: "app/Domain/Sync/Filament/Resources + app/Domain/Products/Filament/Resources"
      via: "->discoverResources(in:, for:)"
      pattern: "discoverResources.*Domain"
---

<objective>
Ship SYNC-08 (CSV report distribution), SYNC-11 (Supplier Sync Status Filament page), and SYNC-12 (Import Issues Filament page), plus the supporting Product/ProductVariant Filament Resources (D-01 expansion). This plan makes the sync pipeline observable to ops and closes the D-08/D-10 reporting loop.

Three subsystems:
1. **Reporting** — alert_recipients migration (`receives_sync_reports` column), SyncReportCsvGenerator (spatie/simple-excel, 11 columns in D-10 order), SupplierSyncReportMail, SyncSupplierCommand wiring to email on completion + abort
2. **Filament Resources** — SyncRunResource (with 2 RelationManagers for errors + items drill-down), ImportIssueResource (resolve action), ProductResource (with VariantsRelationManager)
3. **Shield permission layer** — run `shield:generate --all --panel=admin` to produce permissions for the 5 new Resources, then re-audit 3 Phase 1 policies + 2 new P01 policies per Pitfall P2-H (this plan is the trigger, Plan 05 adds the permanent guardrail)

Purpose: Without this plan, operators cannot see sync results or triage catalogue-health issues. The CSV report is the single D-10 contract for operations visibility. Plans 01-03 produce data; this plan surfaces it.

Output: 1 migration + 1 Mail class + 1 CSV generator + 5 Filament Resources + 3 RelationManagers + 6 Filament Pages + AlertRecipient model/Resource updates + AdminPanelProvider discoverResources update + RolePermissionSeeder LIKE pattern update + 1 Blade email template + 6 test files.

Scope additions beyond REQUIREMENTS.md:
- D-08 — receives_sync_reports column on alert_recipients (CONTEXT.md §decisions)
- D-10 — 11-column CSV format (CONTEXT.md §decisions)
- D-01 ProductResource + ProductVariantResource (RelationManager)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/phases/02-supplier-sync/02-RESEARCH.md
@.planning/phases/02-supplier-sync/02-01-SUMMARY.md
@.planning/phases/02-supplier-sync/02-02-SUMMARY.md
@.planning/phases/02-supplier-sync/02-03-SUMMARY.md
@.planning/phases/01-foundation/01-02-SUMMARY.md
@.planning/phases/01-foundation/01-04-SUMMARY.md
@.planning/phases/01-foundation/01-05-SUMMARY.md

<interfaces>
<!-- Contracts consumed from P01-P03 and Phase 1. -->

From P01 (02-01-data-model):
- SyncRun: items()/errors() HasMany, correlation_id, dry_run, all counters
- SyncRunItem: forRun($runId) scope, 11 CSV columns
- ImportIssue: TYPE_* constants, scopeUnresolved, scopeOfType, correlation_id
- Product: hasMany(variants), is_custom_ms + exclude_from_auto_update casts
- ProductVariant: belongsTo(product), attributes json
- Policies: ProductPolicy, ProductVariantPolicy, SyncRunPolicy, ImportIssuePolicy all hasRole-gated

From P03 (02-03-orchestration):
- SyncSupplierCommand::perform() currently returns after finalise() / abort() — this plan ADDS the generate+mail block at both exit points
- SyncRun statuses: completed, aborted, failed, running, queued

From Phase 1 P05 AlertRecipient (01-05-SUMMARY):
```php
// app/Domain/Alerting/Models/AlertRecipient.php (existing)
class AlertRecipient extends Model
{
    protected $fillable = ['email', 'name', 'is_active', 'notes'];
    protected $casts = ['is_active' => 'bool'];
    // NO receives_sync_reports yet — this plan adds it
}
```

From Phase 1 P02 RolePermissionSeeder (01-02-SUMMARY):
- Uses LIKE patterns: `%_product`, `%_product_variant`, `%_import_issue`, `%_sync_run` (pre-added for forward compat)
- Running `shield:generate --all --panel=admin` after adding a Resource + re-running seeder auto-attaches permissions

From spatie/simple-excel ^3.9 (installed by P02):
```php
use Spatie\SimpleExcel\SimpleExcelWriter;

$writer = SimpleExcelWriter::create($path)->noHeaderRow();
$writer->addRow([...]);
// Buffer flushed on __destruct — Pitfall P2-A: must unset($writer) before Mail::attach($path) reads it
```

From Phase 1 Filament pattern (01-04-SUMMARY SuggestionResource, 01-05-SUMMARY AlertRecipientResource):
- Resource under app/Domain/{Module}/Filament/Resources/
- Pages in .../Resources/{ResourceName}/Pages/
- getEloquentQuery() override with eager loads for N+1 prevention (Gemini MEDIUM 2 pattern)
- approve/reject Actions use BOTH ->authorize() AND ->visible()
- Livewire tests via \Livewire\Livewire::test() (NOT pest-plugin-livewire)

From Phase 1 Pitfall P2-H (RESEARCH.md lines 1207-1217):
- shield:generate --all destroys hand-edited policies
- Phase 1 already fixed: SuggestionPolicy (01-04), AlertRecipientPolicy (01-05), RolePolicy (01-02)
- P01 hand-edited: ProductPolicy, ProductVariantPolicy, SyncRunPolicy, ImportIssuePolicy
- **Post-generate audit plan (this task):**
  1. Commit all hand-edited policies BEFORE running shield:generate
  2. Run shield:generate
  3. `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` — must return empty
  4. If not empty: `git checkout HEAD -- <each damaged policy>`
  5. Plan 05 ships PolicyTemplateIntegrityTest as the permanent guardrail

From AdminPanelProvider (01-02-SUMMARY):
```php
->discoverResources(in: app_path('Domain/Suggestions/Filament/Resources'), for: 'App\\Domain\\Suggestions\\Filament\\Resources')
->discoverResources(in: app_path('Domain/Alerting/Filament/Resources'), for: 'App\\Domain\\Alerting\\Filament\\Resources')
// Phase 2 adds Sync + Products
```
</interfaces>

</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: D-08 alert_recipients migration + AlertRecipient model update + AlertRecipientResource form field + SyncReportCsvGenerator + SupplierSyncReportMail + SyncSupplierCommand wire-in</name>
  <files>
    database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php,
    app/Domain/Alerting/Models/AlertRecipient.php,
    app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php,
    app/Domain/Sync/Reports/SyncReportCsvGenerator.php,
    app/Domain/Sync/Mail/SupplierSyncReportMail.php,
    app/Domain/Sync/Commands/SyncSupplierCommand.php,
    resources/views/emails/supplier-sync-report.blade.php,
    tests/Feature/ReceivesSyncReportsColumnTest.php,
    tests/Feature/SyncReportCsvGeneratorTest.php,
    tests/Feature/SyncReportMailTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §9 CSV Report Generator (lines 897-975 — exact 11-column order + generator code), Pitfall P2-A (lines 1144-1151 — writer flush on destruct; MUST `unset($writer)` before Mail attach)
    - 02-CONTEXT.md D-08 (alert_recipients addition), D-10 (11-column CSV format)
    - 01-05-SUMMARY.md (AlertRecipient model + AlertRecipientResource current shape; AlertDistribution Notifiable)
    - app/Domain/Alerting/Models/AlertRecipient.php + Filament/Resources/AlertRecipientResource.php (current files)
    - spatie/simple-excel README patterns (verified in P02)
  </read_first>
  <behavior>
    Tests in tests/Feature/ReceivesSyncReportsColumnTest.php:
    - Test C1: After migrate, `Schema::hasColumn('alert_recipients', 'receives_sync_reports')` is true and the column is boolean default 1 (true).
    - Test C2: Existing rows (before migration) are backfilled to receives_sync_reports=true (default).
    - Test C3: AlertRecipient::create([...])->receives_sync_reports defaults to true when not specified.
    - Test C4: `AlertRecipient::query()->receivesSyncReports()->get()` returns only rows with receives_sync_reports=true AND is_active=true (chained scope with existing ::active()).
    - Test C5: Migration rollback drops the column (`Schema::hasColumn` returns false after rollback).

    Tests in tests/Feature/SyncReportCsvGeneratorTest.php:
    - Test G1: Given a SyncRun with 3 SyncRunItem rows, `generate($run)` writes a CSV at `storage/app/private/sync-reports/run-{id}.csv` with a header row + 3 data rows.
    - Test G2: Header row has EXACTLY these 11 columns in order: sku, woo_product_id, woo_variation_id, action, reason, old_price, new_price, old_stock, new_stock, error_message, correlation_id.
    - Test G3: Streaming via chunk(500) — feeding 1500 SyncRunItem rows produces a file with 1500 data rows AND memory usage stays under 20MB (sample via memory_get_peak_usage).
    - Test G4: Pitfall P2-A — after `generate()` returns, the file is fully flushed (readable row count matches expected) because the generator explicitly `unset($writer)` before returning the path.
    - Test G5: Empty SyncRun (zero items) produces a CSV with just the header row; path is still returned.

    Tests in tests/Feature/SyncReportMailTest.php:
    - Test M1: Calling `Mail::to(AlertRecipient::active()->receivesSyncReports()->get())->send(new SupplierSyncReportMail($run))` with a completed run sends ONE mail (to the seeded ops@meetingstore.co.uk). Subject: "Supplier sync {id} — {N} updated".
    - Test M2: Aborted run → subject prefixed with `[ABORTED]` and includes `abort_reason`.
    - Test M3: Mail has an attachment whose content matches the generated CSV file (read stream + assertSee header row).
    - Test M4: Recipients with is_active=false OR receives_sync_reports=false are NOT included in the Mail::to list.
    - Test M5: SyncSupplierCommand dispatches Mail after finalise() — run through perform() with `Mail::fake()` and `Event::fake()`; assert exactly 1 mail sent of class SupplierSyncReportMail.
    - Test M6: SyncSupplierCommand dispatches Mail after abort() too — same assertion in abort path.
  </behavior>
  <action>
**1. Create migration `database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php`:**
```php
<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            // D-08: default TRUE so existing seeded ops@meetingstore.co.uk starts receiving reports
            $table->boolean('receives_sync_reports')->nullable()->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->dropColumn('receives_sync_reports');
        });
    }
};
```

**2. Update `app/Domain/Alerting/Models/AlertRecipient.php`** — additive only:
```php
// $fillable — append 'receives_sync_reports'
protected $fillable = ['email', 'name', 'is_active', 'notes', 'receives_sync_reports'];

// $casts — append
protected $casts = [
    'is_active' => 'bool',
    'receives_sync_reports' => 'bool',
];

// Add scope:
public function scopeReceivesSyncReports($query)
{
    return $query->where('receives_sync_reports', true);
}

// Optionally add an active() scope if not already defined in Phase 1:
public function scopeActive($query) { return $query->where('is_active', true); }
```

**3. Update `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php`** — add the opt-out toggle in the form:
```php
// In form() schema, add:
Forms\Components\Toggle::make('receives_sync_reports')
    ->label('Receives Sync Reports')
    ->helperText('D-08: Opt-in to the daily supplier sync CSV report. Default true.')
    ->default(true),

// In table() columns, add:
Tables\Columns\IconColumn::make('receives_sync_reports')
    ->boolean()
    ->label('Reports?'),
```

**4. Create `app/Domain/Sync/Reports/SyncReportCsvGenerator.php`** — RESEARCH §9 verbatim with Pitfall P2-A mitigation:
```php
namespace App\Domain\Sync\Reports;

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use Illuminate\Support\Facades\File;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * D-10 11-column CSV report writer.
 * Pitfall P2-A: SimpleExcelWriter flushes buffer on __destruct. We unset()
 * the writer before returning the path so Mail::attach() reads a complete file.
 */
final class SyncReportCsvGenerator
{
    public function generate(SyncRun $run): string
    {
        $path = storage_path("app/private/sync-reports/run-{$run->id}.csv");
        File::ensureDirectoryExists(dirname($path));

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $writer->addRow([
            'sku', 'woo_product_id', 'woo_variation_id', 'action', 'reason',
            'old_price', 'new_price', 'old_stock', 'new_stock',
            'error_message', 'correlation_id',
        ]);

        SyncRunItem::forRun($run->id)->orderBy('id')->chunk(500, function ($chunk) use ($writer) {
            foreach ($chunk as $item) {
                $writer->addRow([
                    $item->sku,
                    $item->woo_product_id,
                    $item->woo_variation_id,
                    $item->action,
                    $item->reason,
                    $item->old_price,
                    $item->new_price,
                    $item->old_stock,
                    $item->new_stock,
                    $item->error_message,
                    $item->correlation_id,
                ]);
            }
        });

        // Pitfall P2-A: force flush by releasing the writer before returning
        unset($writer);

        return $path;
    }
}
```

**5. Create `app/Domain/Sync/Mail/SupplierSyncReportMail.php`:**
```php
namespace App\Domain\Sync\Mail;

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Reports\SyncReportCsvGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupplierSyncReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SyncRun $run,
        public readonly string $csvPath,
        public readonly bool $aborted = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->aborted
            ? "[ABORTED] Supplier sync {$this->run->id} — {$this->run->abort_reason}"
            : "Supplier sync {$this->run->id} — {$this->run->updated_count} updated";
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-sync-report',
            with: [
                'run' => $this->run,
                'aborted' => $this->aborted,
                'stats' => $this->run->only([
                    'total_skus', 'updated_count', 'skipped_count',
                    'failed_count', 'missing_count', 'unknown_sku_count',
                ]),
                'abortReason' => $this->run->abort_reason,
                'abortMessage' => $this->run->abort_message,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->csvPath)
                ->as("supplier-sync-run-{$this->run->id}.csv")
                ->withMime('text/csv'),
        ];
    }
}
```

**6. Create `resources/views/emails/supplier-sync-report.blade.php`:**
```blade
<!doctype html>
<html><body style="font-family: system-ui, sans-serif; color: #333;">
<h2>Supplier Sync Report — Run #{{ $run->id }}</h2>

@if ($aborted)
    <p style="color: #c00;"><strong>Status: ABORTED</strong> — {{ $abortReason }}</p>
    <p><strong>Reason:</strong> {{ $abortMessage }}</p>
@else
    <p style="color: #080;"><strong>Status: Completed</strong></p>
@endif

<p><strong>Mode:</strong> {{ $run->dry_run ? 'DRY-RUN (no Woo writes)' : 'LIVE' }}</p>
<p><strong>Correlation ID:</strong> <code>{{ $run->correlation_id }}</code></p>
<p><strong>Started:</strong> {{ $run->started_at?->toIso8601String() }}</p>
<p><strong>Completed:</strong> {{ $run->completed_at?->toIso8601String() ?? '—' }}</p>

<h3>Counts</h3>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">
    <tr><th>Metric</th><th>Count</th></tr>
    <tr><td>Total SKUs</td><td>{{ $stats['total_skus'] }}</td></tr>
    <tr><td>Updated</td><td>{{ $stats['updated_count'] }}</td></tr>
    <tr><td>Skipped</td><td>{{ $stats['skipped_count'] }}</td></tr>
    <tr><td>Failed</td><td>{{ $stats['failed_count'] }}</td></tr>
    <tr><td>Missing at supplier</td><td>{{ $stats['missing_count'] }}</td></tr>
    <tr><td>Unknown SKUs</td><td>{{ $stats['unknown_sku_count'] }}</td></tr>
</table>

<p>Attached: per-SKU CSV report (11 columns per D-10).</p>

<p style="font-size: 0.9em; color: #666;">
    This run can be drilled-down at <code>/admin/sync-runs/{{ $run->id }}</code>.
    Aborted runs are resumable via <code>php artisan sync:supplier --resume={{ $run->id }}</code>.
</p>
</body></html>
```

**7. Update `app/Domain/Sync/Commands/SyncSupplierCommand.php`** — wire in generator + mail at both exit points (finalise and abort):

Add `use` statements:
```php
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Sync\Mail\SupplierSyncReportMail;
use App\Domain\Sync\Reports\SyncReportCsvGenerator;
use Illuminate\Support\Facades\Mail;
```

After `$run->refresh()->finalise();`:
```php
$this->emailReport($run, aborted: false);
```

Inside the `catch (SyncAbortException $e)`, after `$run->abort(...)`:
```php
$this->emailReport($run, aborted: true);
```

Add method:
```php
private function emailReport(SyncRun $run, bool $aborted): void
{
    $csvPath = app(SyncReportCsvGenerator::class)->generate($run);

    $recipients = AlertRecipient::query()
        ->where('is_active', true)
        ->where('receives_sync_reports', true)
        ->get();

    if ($recipients->isEmpty()) {
        $this->warn("No active alert_recipients with receives_sync_reports=true — report CSV written to {$csvPath} but no email sent.");
        return;
    }

    Mail::to($recipients->pluck('email')->all())
        ->send(new SupplierSyncReportMail($run, $csvPath, aborted: $aborted));

    $this->info("Report CSV: {$csvPath}");
    $this->info("Emailed to: " . $recipients->pluck('email')->implode(', '));
}
```

**8. Write the 3 test files** per <behavior>. Use `Mail::fake()`, `Queue::fake()`, factories from P01. Verify Pitfall P2-A by reading the CSV row count after `generate()` returns (should match expected — if writer wasn't flushed, read would be short).

**Self-check:**
```bash
php artisan migrate --force  # adds receives_sync_reports column
vendor/bin/pest --filter=ReceivesSyncReportsColumn --filter=SyncReportCsvGenerator --filter=SyncReportMail
vendor/bin/pest  # full suite
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=ReceivesSyncReportsColumn &amp;&amp; vendor/bin/pest --filter=SyncReportCsvGenerator &amp;&amp; vendor/bin/pest --filter=SyncReportMail</automated>
  </verify>
  <done>
    - `php artisan migrate --force` applies the receives_sync_reports column cleanly on dev + testing DBs
    - AlertRecipient model has `receives_sync_reports` in $fillable + $casts + scopeReceivesSyncReports
    - AlertRecipientResource form has the toggle field
    - SyncReportCsvGenerator writes CSV at storage/app/private/sync-reports/ with exact 11 D-10 columns
    - Pitfall P2-A mitigated: unset($writer) before return; reading the file immediately after generate() returns the full row count
    - SupplierSyncReportMail attaches CSV, envelope subject reflects aborted/completed
    - SyncSupplierCommand emails report at finalise() AND abort(); skips mail gracefully when no recipients
    - resources/views/emails/supplier-sync-report.blade.php renders correctly with all stats
    - 3 new test files, 16+ tests green
    - Full Pest suite ≥ 207 passing
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Ship SyncRunResource + ImportIssueResource + ProductResource + RelationManagers + AdminPanelProvider + RolePermissionSeeder + shield:generate audit</name>
  <files>
    app/Domain/Sync/Filament/Resources/SyncRunResource.php,
    app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ListSyncRuns.php,
    app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ViewSyncRun.php,
    app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncErrorsRelationManager.php,
    app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncRunItemsRelationManager.php,
    app/Domain/Sync/Filament/Resources/ImportIssueResource.php,
    app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/ListImportIssues.php,
    app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/EditImportIssue.php,
    app/Domain/Products/Filament/Resources/ProductResource.php,
    app/Domain/Products/Filament/Resources/ProductResource/Pages/ListProducts.php,
    app/Domain/Products/Filament/Resources/ProductResource/Pages/ViewProduct.php,
    app/Domain/Products/Filament/Resources/ProductResource/Pages/EditProduct.php,
    app/Domain/Products/Filament/Resources/ProductResource/RelationManagers/VariantsRelationManager.php,
    app/Providers/Filament/AdminPanelProvider.php,
    database/seeders/RolePermissionSeeder.php,
    tests/Feature/SyncRunResourceTest.php,
    tests/Feature/ImportIssueResourceTest.php,
    tests/Feature/ProductResourceTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §11 Filament Resources (lines 1029-1057 — exact column/filter shapes per Resource + Shield permission integration warning), Pitfall P2-G (lines 1199-1206 — N+1 prevention with withCount), Pitfall P2-H (lines 1207-1217 — policy restore protocol)
    - 02-CONTEXT.md — lines 152-160 (Resource shapes)
    - 01-04-SUMMARY.md (SuggestionResource template: getEloquentQuery() + Filament page structure + authorize on Actions)
    - 01-05-SUMMARY.md (AlertRecipientResource template; AdminPanelProvider discoverResources pattern)
    - 01-02-SUMMARY.md (RolePermissionSeeder LIKE-pattern invariants, Shield auto-role disable)
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (pattern template)
    - app/Providers/Filament/AdminPanelProvider.php (existing discoverResources calls)
    - database/seeders/RolePermissionSeeder.php (LIKE patterns already include %_product, %_product_variant, %_import_issue, %_sync_run per Phase 1 forward-compat)
  </read_first>
  <behavior>
    Tests in tests/Feature/SyncRunResourceTest.php:
    - Test S1: Admin can reach `/admin/sync-runs` (ListSyncRuns Livewire component mounts).
    - Test S2: read_only role can also reach the list (SyncRunPolicy viewAny=true for all 4 roles per P01).
    - Test S3: Table shows columns: id, started_at, status (badge), dry_run (icon), updated_count, failed_count, correlation_id (truncated).
    - Test S4: Filter by status=aborted returns only aborted runs.
    - Test S5: getEloquentQuery() includes ->withCount('errors') — assert via `SyncRunResource::getEloquentQuery()->getCountForTable('errors')` OR verify 10 runs list executes ≤ 12 queries (N+1 prevention).
    - Test S6: SyncErrorsRelationManager on ViewSyncRun lists per-SKU errors with columns sku, woo_product_id, error_class, error_message, created_at.
    - Test S7: SyncRunItemsRelationManager lists items with all 11 fields; action column is a badge.
    - Test S8: "Retry aborted run" header action — visible + authorize closure returns false for non-admin, true for admin (test via the closure signature check similar to Phase 1 SuggestionResource test pattern).

    Tests in tests/Feature/ImportIssueResourceTest.php:
    - Test I1: Admin + pricing_manager can reach `/admin/import-issues`; sales + read_only see list but cannot edit.
    - Test I2: Table columns: sku, issue_type (badge), detected_at, resolved_at (nullable badge showing "Unresolved" if null), notes (truncated).
    - Test I3: Filter by issue_type=unknown_sku returns only unknown-SKU rows.
    - Test I4: Bulk action "Mark resolved" sets resolved_at=now() on selected rows (admin + pricing_manager only via authorize).
    - Test I5: Edit form allows pricing_manager to update `notes` but NOT to change issue_type (form disables that field; admin can override).

    Tests in tests/Feature/ProductResourceTest.php:
    - Test P1: Admin + pricing_manager can reach `/admin/products` AND edit; sales + read_only view-only.
    - Test P2: List shows woo_product_id, sku, name, type (badge simple/variable), status, stock_status, buy_price, sell_price, is_custom_ms (icon), last_synced_at.
    - Test P3: VariantsRelationManager on ViewProduct (only for `type=variable` products) lists variants with sku, buy_price, stock_quantity, status.
    - Test P4: Edit form allows pricing_manager to change buy_price, sell_price, cost_price but NOT woo_product_id, sku, type (disabled — source of truth is Woo).
    - Test P5: Filter by `is_custom_ms = true` returns only custom-ms products.
    - Test P6: Search by SKU works (both product.sku AND variant.sku — search resolves via union query or relation search).
  </behavior>
  <action>
**1. Build `app/Domain/Sync/Filament/Resources/SyncRunResource.php`** — follow SuggestionResource template. Key code:
```php
namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\SyncRunResource\Pages;
use App\Domain\Sync\Models\SyncRun;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Sync';
    protected static ?int $navigationSort = 10;
    protected static ?string $recordTitleAttribute = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['errors', 'items'])  // Pitfall P2-G N+1 prevention
            ->latest('started_at');
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('duration')->label('Duration')->state(fn (SyncRun $r) => $r->completed_at ? $r->started_at->diffForHumans($r->completed_at, true) : '—'),
            Tables\Columns\BadgeColumn::make('status')->colors([
                'primary' => 'queued', 'warning' => 'running',
                'success' => 'completed', 'danger' => ['aborted', 'failed'],
            ]),
            Tables\Columns\IconColumn::make('dry_run')->boolean()->label('Dry-run?'),
            Tables\Columns\TextColumn::make('updated_count')->label('Updated'),
            Tables\Columns\TextColumn::make('failed_count')->label('Failed'),
            Tables\Columns\TextColumn::make('errors_count')->label('Error Rows'),
            Tables\Columns\TextColumn::make('correlation_id')->limit(12)->copyable(),
        ])
        ->filters([
            SelectFilter::make('status')->multiple()->options([
                'queued' => 'Queued', 'running' => 'Running',
                'completed' => 'Completed', 'aborted' => 'Aborted', 'failed' => 'Failed',
            ]),
            Tables\Filters\TernaryFilter::make('dry_run')->label('Dry-run'),
        ])
        ->headerActions([
            // Admin-only "Retry" — Pitfall K pattern: BOTH authorize AND visible
            Tables\Actions\Action::make('retry')
                ->label('Retry aborted run')
                ->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                ->url(fn () => null),  // placeholder — wire to a dispatch action when needed
        ]);
    }

    public static function getRelations(): array
    {
        return [
            SyncRunResource\RelationManagers\SyncErrorsRelationManager::class,
            SyncRunResource\RelationManagers\SyncRunItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
            'view' => Pages\ViewSyncRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool { return false; }  // orchestrator-only
}
```

Pages: ListSyncRuns (ListRecords), ViewSyncRun (ViewRecord) — scaffold only.

RelationManagers: SyncErrorsRelationManager + SyncRunItemsRelationManager — list-only (no create/edit), columns per <behavior>.

**2. Build `app/Domain/Sync/Filament/Resources/ImportIssueResource.php`** — form + table + resolve action. Key:
```php
public static function table(Tables\Table $table): Tables\Table
{
    return $table->columns([
        Tables\Columns\TextColumn::make('sku')->searchable(),
        Tables\Columns\BadgeColumn::make('issue_type'),
        Tables\Columns\TextColumn::make('detected_at')->dateTime()->sortable(),
        Tables\Columns\TextColumn::make('resolved_at')->dateTime()->placeholder('Unresolved'),
        Tables\Columns\TextColumn::make('notes')->limit(50),
    ])
    ->filters([
        SelectFilter::make('issue_type')->multiple()->options([
            'missing_at_supplier' => 'Missing at supplier',
            'unknown_sku' => 'Unknown SKU',
            'missing_cost_price' => 'Missing cost/price',
            'exclude_flag_no_metadata' => 'Exclude flag, no metadata',
        ]),
        Tables\Filters\TernaryFilter::make('resolved')
            ->nullable()->placeholder('All')->trueLabel('Resolved')->falseLabel('Unresolved')
            ->queries(
                true: fn ($q) => $q->whereNotNull('resolved_at'),
                false: fn ($q) => $q->whereNull('resolved_at'),
            ),
    ])
    ->bulkActions([
        Tables\Actions\BulkAction::make('markResolved')
            ->label('Mark resolved')
            ->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
            ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
            ->action(fn ($records) => $records->each(fn ($r) => $r->update(['resolved_at' => now()]))),
    ]);
}
```

Pages: ListImportIssues, EditImportIssue.

**3. Build `app/Domain/Products/Filament/Resources/ProductResource.php` + VariantsRelationManager** — pricing_manager-editable fields (buy_price, sell_price, cost_price), immutable identity fields (woo_product_id, sku, type). Pages: ListProducts, ViewProduct, EditProduct.

For the VariantsRelationManager — only show when `$ownerRecord->type === 'variable'`:
```php
public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
{
    return $ownerRecord->type === 'variable';
}
```

**4. Update `app/Providers/Filament/AdminPanelProvider.php`** — add 2 discoverResources calls (additive):
```php
->discoverResources(in: app_path('Domain/Sync/Filament/Resources'), for: 'App\\Domain\\Sync\\Filament\\Resources')
->discoverResources(in: app_path('Domain/Products/Filament/Resources'), for: 'App\\Domain\\Products\\Filament\\Resources')
```

**5. Update `database/seeders/RolePermissionSeeder.php`** — verify these LIKE patterns are already present from Phase 1 (01-02-SUMMARY said they were forward-compat seeded). If not, add:
```php
// pricing_manager:
'%_product', '%_product_variant', '%_import_issue',

// read_only:
'view_any_product', 'view_product', 'view_any_product_variant', 'view_product_variant',
'view_any_sync_run', 'view_sync_run', 'view_any_import_issue', 'view_import_issue',
```

**6. Run `shield:generate` + post-audit (Pitfall P2-H protocol):**
```bash
# BEFORE running, commit all hand-edited policies so `git checkout HEAD -- <file>` works if needed:
git add app/Policies/*.php app/Domain/*/Policies/*.php
git diff --cached --stat  # snapshot — save for post-generate comparison

php artisan shield:generate --all --panel=admin --no-interaction

# Grep for template literal leakage:
grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/ 2>/dev/null
# Expected: empty output. If non-empty, restore each file via:
# git checkout HEAD -- <path>

php artisan db:seed --class=RolePermissionSeeder --force
```

Expected damage to re-audit (hand-edited policies that will be regenerated):
- `app/Policies/RolePolicy.php` — Phase 1's `{{ Placeholder }}` fix may be reverted; restore via `git checkout HEAD -- app/Policies/RolePolicy.php`
- `app/Domain/Suggestions/Policies/SuggestionPolicy.php` — Phase 1 hasRole override; restore from git
- `app/Domain/Alerting/Policies/AlertRecipientPolicy.php` — Phase 1 hasRole; restore from git
- `app/Domain/Products/Policies/ProductPolicy.php` (P01) — may be regenerated; restore from git
- `app/Domain/Products/Policies/ProductVariantPolicy.php` (P01) — restore from git
- `app/Domain/Sync/Policies/SyncRunPolicy.php` (P01) — restore from git
- `app/Domain/Sync/Policies/ImportIssuePolicy.php` (P01) — restore from git

Shield MAY generate NEW Policy files for new Resources (ProductPolicy already exists → Shield won't overwrite? Verify. If Shield generates `app/Policies/ProductPolicy.php` at the ROOT path, delete it — our P01 policy lives at `app/Domain/Products/Policies/ProductPolicy.php`. Register the correct one via Gate::policy in AppServiceProvider (P01 already did this).

**7. Write 3 Filament test files** per <behavior>. Use `\Livewire\Livewire::test()`, `actingAs(User::factory()->create()->assignRole('admin'))` per Phase 1 pattern. Verify N+1 prevention via `DB::getQueryLog()` bounded assertion.

**Self-check:**
```bash
vendor/bin/pest --filter=SyncRunResource --filter=ImportIssueResource --filter=ProductResource
vendor/bin/pest  # full suite — MUST stay green after shield:generate + policy restore
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=SyncRunResource &amp;&amp; vendor/bin/pest --filter=ImportIssueResource &amp;&amp; vendor/bin/pest --filter=ProductResource &amp;&amp; vendor/bin/pest</automated>
  </verify>
  <done>
    - 5 Filament Resources + 3 RelationManagers + 6 Pages under app/Domain/{Sync,Products}/Filament/Resources/
    - AdminPanelProvider discovers 4 resource directories (Suggestions + Alerting from Phase 1, Sync + Products new)
    - `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/ 2>/dev/null` returns EMPTY (Pitfall P2-H mitigated)
    - `php artisan shield:generate --all --panel=admin && php artisan db:seed --class=RolePermissionSeeder --force` runs idempotently
    - Visiting `/admin/sync-runs`, `/admin/import-issues`, `/admin/products` as admin renders Livewire components successfully (via Livewire::test)
    - 3 Resource test files, ≥ 20 tests green
    - Full Pest suite ≥ 227 passing, 0 regressions
    - N+1 query test bounded: 10 SyncRun rows render in ≤ 12 DB queries total (P2-G prevention)
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| SyncSupplierCommand → Mail::to(recipients) | Email addresses from alert_recipients table; CSV attachment contains SKU+price (business confidential) |
| CSV file → storage/app/private/sync-reports/ | File persisted at `storage/app/private/`; not web-served but any server compromise reveals all past reports |
| Filament /admin/* → Resources | Route gating + policy + Shield permission triple-layer (Phase 1 Pitfall K) |
| Filament table filters → DB | User-supplied filter values routed through Eloquent parameter binding (Filament handles) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-04-01 | Information Disclosure | CSV report contains SKU + price (business confidential) | mitigate | Attached to email (SMTP is in-transit TLS on standard MX); report stored at storage/app/private/ (NOT public symlink). Plan 05 adds `sync-reports:prune` to retention prune set — candidate for deferred. |
| T-02-04-02 | Information Disclosure | Email distribution list | mitigate | AlertRecipient.receives_sync_reports opt-in (D-08 default true; operators can toggle off). Admin-only CRUD on /admin/alert-recipients (Phase 1 Pitfall K). |
| T-02-04-03 | Tampering | AlertRecipient.receives_sync_reports flip — malicious operator suppresses alerts | mitigate | spatie/activitylog on AlertRecipient logs the toggle with batch_uuid=correlation_id of the admin's request; audit_log reveals after-the-fact. Admin-only CRUD limits attack surface. |
| T-02-04-04 | Elevation of Privilege | ImportIssueResource resolve bulk action | mitigate | `->authorize(fn => hasAnyRole([admin, pricing_manager]))` on the action closure (runtime) PLUS ImportIssuePolicy (policy-layer). Test I4 asserts. |
| T-02-04-05 | Elevation of Privilege | shield:generate regenerates hand-edited policies (Pitfall P2-H) | mitigate | Post-generate grep check + `git checkout HEAD --` protocol documented in action text. Plan 05 ships PolicyTemplateIntegrityTest as permanent architecture guardrail. |
| T-02-04-06 | Information Disclosure | VariantsRelationManager shows all variants for variable products — including ones with `sell_price` that sales/read_only shouldn't see | accept | ProductVariantPolicy gates viewAny; read_only CAN view (per D-02 Phase 1 — "read_only sees reports"). Prices are visible to all 4 roles on the Woo storefront anyway. Not a genuine disclosure. |
| T-02-04-07 | Denial of Service | CSV generation for a 15k-SKU run allocating full result set in memory | mitigate | SyncRunItem::forRun chunked at 500 rows; memory usage stays constant. Test G3 asserts < 20MB peak. |
| T-02-04-08 | Tampering | Mail attachment path manipulation — attacker crafts CSV path to read arbitrary file | mitigate | `storage_path("app/private/sync-reports/run-{$run->id}.csv")` uses integer $run->id (Eloquent PK int-coerced); path never comes from user input. Attachment::fromPath() path-bound to storage/. |
</threat_model>

<verification>
1. **Migration applied on both DBs:**
   ```bash
   php artisan migrate --force
   DB_DATABASE=meetingstore_ops_testing php artisan migrate --force
   ```
   `receives_sync_reports` column present on alert_recipients.

2. **Policy integrity after shield:generate:**
   ```bash
   grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/ 2>/dev/null
   ```
   Empty output.

3. **End-to-end dry-run with report:**
   ```bash
   php artisan sync:supplier  # uses fake supplier creds — will fail, but ABORT path still generates report
   # OR seed a test SyncRun + SyncRunItem via factory and call the generator directly
   php artisan tinker --execute="dd(app(\\App\\Domain\\Sync\\Reports\\SyncReportCsvGenerator::class)->generate(\\App\\Domain\\Sync\\Models\\SyncRun::factory()->completed()->has(\\App\\Domain\\Sync\\Models\\SyncRunItem::factory()->count(3), 'items')->create()))"
   ```
   Returns a path like `.../sync-reports/run-N.csv`; file has 4 lines (header + 3).

4. **Filament routes:**
   ```bash
   php artisan route:list | grep admin.sync-runs
   php artisan route:list | grep admin.import-issues
   php artisan route:list | grep admin.products
   ```
   All three return registered routes.

5. **Full Pest suite:** `vendor/bin/pest` ≥ 227 passing.

6. **Deptrac:** `vendor/bin/deptrac analyse --no-progress` exits 0.
</verification>

<success_criteria>
- D-08 migration applied; receives_sync_reports defaulted true for existing recipients
- AlertRecipientResource has the opt-out toggle field
- SyncReportCsvGenerator writes the EXACT 11-column CSV in D-10 order; Pitfall P2-A mitigated by explicit unset($writer)
- SupplierSyncReportMail attaches the CSV and distinguishes aborted vs completed via envelope subject
- SyncSupplierCommand emails at finalise() AND abort(); warns (non-fatal) when no recipients
- SyncRunResource (SYNC-11) shows run history with status/counts + 2 RelationManagers (errors + items)
- ImportIssueResource (SYNC-12) shows 4 issue types with filter + resolve bulk action gated to admin/pricing_manager
- ProductResource (D-01 expansion) allows pricing_manager to edit price fields + VariantsRelationManager for variables
- `shield:generate --all --panel=admin` run without regressing the 7 hand-edited policies
- Full Pest suite ≥ 227 passing, 0 regressions, 2 skipped as-designed
- Deptrac 0 violations
</success_criteria>

<output>
Create `.planning/phases/02-supplier-sync/02-04-SUMMARY.md` after completion with:
- receives_sync_reports migration outcome
- CSV generator verified against D-10 column order
- Pitfall P2-A mitigation verified (write-then-read-row-count assertion)
- Shield regenerate: list of policies that needed `git checkout HEAD --` restoration (expected: RolePolicy + SuggestionPolicy + AlertRecipientPolicy + 4 P01 policies = 7 max)
- Filament navigation structure (new "Sync" group)
- Whether RolePermissionSeeder needed additions beyond Phase 1's LIKE patterns
</output>
