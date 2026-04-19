<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — AlertRecipient.receives_crm_alerts column
|--------------------------------------------------------------------------
|
| D-12: opt-in flag for CRM push-failed alerts. Default false for new rows;
| seeded ops@meetingstore.co.uk fallback force-defaulted to true by the
| migration so CRM alerts always have a safe target.
*/

it('has receives_crm_alerts column defaulting to false for new rows', function (): void {
    $r = AlertRecipient::create([
        'email' => 'new-recipient@example.com',
        'name' => 'Fresh',
        'is_active' => true,
    ]);

    expect($r->refresh()->receives_crm_alerts)->toBeFalse();
});

it('seeds ops@meetingstore.co.uk with receives_crm_alerts=true', function (): void {
    $this->seed(\Database\Seeders\AlertRecipientSeeder::class);

    $fallback = AlertRecipient::where('email', 'ops@meetingstore.co.uk')->firstOrFail();
    expect($fallback->receives_crm_alerts)->toBeTrue();
});

it('scopeReceivesCrmAlerts filters to opted-in rows', function (): void {
    AlertRecipient::create(['email' => 'a@x.com', 'name' => 'A', 'is_active' => true, 'receives_crm_alerts' => true]);
    AlertRecipient::create(['email' => 'b@x.com', 'name' => 'B', 'is_active' => true, 'receives_crm_alerts' => false]);
    AlertRecipient::create(['email' => 'c@x.com', 'name' => 'C', 'is_active' => true, 'receives_crm_alerts' => true]);

    $emails = AlertRecipient::receivesCrmAlerts()->pluck('email')->all();
    expect($emails)->toContain('a@x.com', 'c@x.com');
    expect($emails)->not->toContain('b@x.com');
});
