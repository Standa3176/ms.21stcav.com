<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 01 Task 1 — D-08 receives_weekly_digest migration + casting
|--------------------------------------------------------------------------
|
| Sibling to the Phase 2 ReceivesSyncReportsColumnTest / Phase 6 Plan 04
| AlertRecipientAutoCreateToggleTest — covers the new column shape plus the
| Pitfall P6-D belt-and-braces UPDATE backfill.
*/

it('adds the receives_weekly_digest column to alert_recipients', function (): void {
    expect(Schema::hasColumn('alert_recipients', 'receives_weekly_digest'))->toBeTrue();
});

it('backfills existing rows to true on migration up', function (): void {
    // Insert a row with the column explicitly NULL via raw SQL (bypasses the
    // model-level default) then re-run the belt-and-braces UPDATE the same
    // way the migration does. This mirrors the production scenario where a
    // historical row ended up NULL despite the DEFAULT clause.
    $id = DB::table('alert_recipients')->insertGetId([
        'email' => 'legacy-null@example.test',
        'name' => 'Legacy Null',
        'is_active' => true,
        'receives_weekly_digest' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-run the same UPDATE the migration performs.
    DB::table('alert_recipients')
        ->whereNull('receives_weekly_digest')
        ->update(['receives_weekly_digest' => true]);

    $recipient = AlertRecipient::find($id);
    expect($recipient->receives_weekly_digest)->toBeTrue();
});

it('casts receives_weekly_digest as boolean on the AlertRecipient model', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'cast-digest@example.test',
        'name' => 'Cast Test',
        'is_active' => true,
        'receives_weekly_digest' => 1,
    ]);

    $recipient->refresh();

    expect($recipient->receives_weekly_digest)->toBeBool()->toBeTrue();
});

it('defaults receives_weekly_digest to true when not specified', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'default-digest@example.test',
        'name' => 'Default',
        'is_active' => true,
    ]);

    $recipient->refresh();

    // DEFAULT TRUE at migration level; casts to bool on the model.
    expect($recipient->receives_weekly_digest)->toBeTrue();
});

it('allows explicit false via fillable', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'opted-out-digest@example.test',
        'name' => 'Opted Out',
        'is_active' => true,
        'receives_weekly_digest' => false,
    ]);

    $recipient->refresh();

    expect($recipient->receives_weekly_digest)->toBeFalse();
});

it('scopeReceivesWeeklyDigest filters to opted-in rows only', function (): void {
    AlertRecipient::create(['email' => 'wd-in-1@example.test', 'is_active' => true, 'receives_weekly_digest' => true]);
    AlertRecipient::create(['email' => 'wd-in-2@example.test', 'is_active' => true, 'receives_weekly_digest' => true]);
    AlertRecipient::create(['email' => 'wd-out@example.test', 'is_active' => true, 'receives_weekly_digest' => false]);

    $emails = AlertRecipient::query()->receivesWeeklyDigest()->pluck('email')->all();

    expect($emails)->toContain('wd-in-1@example.test', 'wd-in-2@example.test');
    expect($emails)->not->toContain('wd-out@example.test');
});
