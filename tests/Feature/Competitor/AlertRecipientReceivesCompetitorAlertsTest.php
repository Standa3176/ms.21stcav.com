<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 01 Task 1 — AlertRecipient.receives_competitor_alerts column
|--------------------------------------------------------------------------
|
| Mirrors the Phase 2 D-08 / Phase 4 D-12 pattern. Default false for new
| rows; seeded ops@meetingstore.co.uk is force-updated to true by migration
| so Pitfall M "no active recipient" outage can't strand competitor alerts.
*/

it('has receives_competitor_alerts column defaulting to false for new rows', function (): void {
    $r = AlertRecipient::create([
        'email' => 'new-competitor-recipient@example.com',
        'name' => 'Fresh',
        'is_active' => true,
    ]);

    expect($r->refresh()->receives_competitor_alerts)->toBeFalse();
});

it('seeds ops@meetingstore.co.uk with receives_competitor_alerts=true via migration', function (): void {
    $this->seed(\Database\Seeders\AlertRecipientSeeder::class);

    $fallback = AlertRecipient::where('email', 'ops@meetingstore.co.uk')->firstOrFail();
    expect($fallback->receives_competitor_alerts)->toBeTrue();
});

it('scopeReceivesCompetitorAlerts filters to opted-in rows', function (): void {
    AlertRecipient::create(['email' => 'ca1@x.com', 'name' => 'A', 'is_active' => true, 'receives_competitor_alerts' => true]);
    AlertRecipient::create(['email' => 'ca2@x.com', 'name' => 'B', 'is_active' => true, 'receives_competitor_alerts' => false]);
    AlertRecipient::create(['email' => 'ca3@x.com', 'name' => 'C', 'is_active' => true, 'receives_competitor_alerts' => true]);

    $emails = AlertRecipient::receivesCompetitorAlerts()->pluck('email')->all();
    expect($emails)->toContain('ca1@x.com', 'ca3@x.com');
    expect($emails)->not->toContain('ca2@x.com');
});

it('stores receives_competitor_alerts via fillable + casts boolean', function (): void {
    $r = AlertRecipient::create([
        'email' => 'cast-check@example.com',
        'name' => 'Cast',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    expect($r->fresh()->receives_competitor_alerts)->toBeTrue();
    expect(is_bool($r->fresh()->receives_competitor_alerts))->toBeTrue();
});
