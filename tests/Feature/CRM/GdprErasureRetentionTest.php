<?php

declare(strict_types=1);

use App\Domain\CRM\Models\GdprErasureLogEntry;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 2 — gdpr_erasure_log retention guardrail (CRM-13).
|--------------------------------------------------------------------------
|
| The `audit_log` retention (Phase 1 D-04) prunes entries older than 365
| days via `php artisan activitylog:prune`. `gdpr_erasure_log` is INTENDED
| to survive that cap — regulator queries can reach back further than the
| default 1-year audit window. This test plants a 5-year-old erasure row,
| runs ALL prune commands, and asserts the row is still on disk.
|
| If a future plan adds a new prune command, THIS test's failure will be
| the canary that catches the regression (the developer needs to add an
| explicit `exclude gdpr_erasure_log` clause).
*/

it('gdpr_erasure_log is NOT touched by any prune command', function (): void {
    // Plant a 5-year-old erasure row.
    $entry = GdprErasureLogEntry::create([
        'email_hash' => hash('sha256', 'ancient@customer.test'),
        'contact_bitrix_id' => 'C1',
        'deal_bitrix_ids' => ['D1'],
        'actor_id' => null,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'fields_scrubbed_count' => 22,
        'status' => GdprErasureLogEntry::STATUS_APPLIED,
        'erased_at' => now()->subYears(5),
    ]);
    // Backdate created_at to bypass any created_at-based cutoff too.
    DB::table('gdpr_erasure_log')
        ->where('id', $entry->id)
        ->update(['created_at' => now()->subYears(5)]);

    $this->artisan('activitylog:prune', ['--days' => 30])->assertExitCode(0);
    $this->artisan('sync-errors:prune', ['--days' => 30])->assertExitCode(0);
    $this->artisan('sync-diffs:prune')->assertExitCode(0);   // no --days flag per Phase 1 D-08
    $this->artisan('integration-events:prune', ['--days' => 30])->assertExitCode(0);

    expect(GdprErasureLogEntry::where('id', $entry->id)->exists())->toBeTrue();
});
