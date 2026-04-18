<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 02-04 Task 1 — D-08 `receives_sync_reports` column + scope tests.
 */
it('has the receives_sync_reports column after migration', function (): void {
    expect(Schema::hasColumn('alert_recipients', 'receives_sync_reports'))->toBeTrue();
});

it('defaults receives_sync_reports to true when not specified', function (): void {
    $r = AlertRecipient::create([
        'email' => 'default-user@example.test',
        'name' => 'Default',
        'is_active' => true,
    ]);

    $r->refresh();
    expect($r->receives_sync_reports)->toBeTrue();
});

it('allows explicit false via fillable', function (): void {
    $r = AlertRecipient::create([
        'email' => 'opted-out@example.test',
        'name' => 'Opted Out',
        'is_active' => true,
        'receives_sync_reports' => false,
    ]);

    expect($r->receives_sync_reports)->toBeFalse();
});

it('casts receives_sync_reports as boolean', function (): void {
    $r = AlertRecipient::create([
        'email' => 'cast-test@example.test',
        'is_active' => true,
        'receives_sync_reports' => 1,
    ]);

    $r->refresh();
    expect($r->receives_sync_reports)->toBeBool()->toBeTrue();
});

it('active + receivesSyncReports scopes chain correctly', function (): void {
    AlertRecipient::create(['email' => 'a@example.test', 'is_active' => true, 'receives_sync_reports' => true]);
    AlertRecipient::create(['email' => 'b@example.test', 'is_active' => true, 'receives_sync_reports' => false]);
    AlertRecipient::create(['email' => 'c@example.test', 'is_active' => false, 'receives_sync_reports' => true]);
    AlertRecipient::create(['email' => 'd@example.test', 'is_active' => false, 'receives_sync_reports' => false]);

    $emails = AlertRecipient::query()
        ->active()
        ->receivesSyncReports()
        ->pluck('email')
        ->all();

    expect($emails)->toBe(['a@example.test']);
});
