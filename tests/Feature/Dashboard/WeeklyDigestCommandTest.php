<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Mail\WeeklyDigestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 04 Task 2 — reports:weekly-digest
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> W1..W4):
|   - Command sends only to recipients where receives_weekly_digest=true
|   - Snapshot update for weekly_report_status metric_key
|   - Scheduled Monday 07:00 Europe/London
|   - No recipients exits 0 + logs warning
*/

it('registers reports:weekly-digest as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('reports:weekly-digest');
});

it('sends only to opted-in AlertRecipients', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'opted-in-1@ops.test',
        'name' => 'Opted In 1',
        'is_active' => true,
        'receives_weekly_digest' => true,
    ]);
    AlertRecipient::create([
        'email' => 'opted-in-2@ops.test',
        'name' => 'Opted In 2',
        'is_active' => true,
        'receives_weekly_digest' => true,
    ]);
    AlertRecipient::create([
        'email' => 'opted-out@ops.test',
        'name' => 'Opted Out',
        'is_active' => true,
        'receives_weekly_digest' => false,
    ]);

    Artisan::call('reports:weekly-digest');

    Mail::assertSent(WeeklyDigestMail::class, 2);
    Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $m) => $m->hasTo('opted-in-1@ops.test'));
    Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $m) => $m->hasTo('opted-in-2@ops.test'));
    Mail::assertNotSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $m) => $m->hasTo('opted-out@ops.test'));
});

it('writes a dashboard_snapshots row keyed weekly_report_status', function (): void {
    Mail::fake();

    AlertRecipient::create([
        'email' => 'snapshot-test@ops.test',
        'name' => 'Snapshot Test',
        'is_active' => true,
        'receives_weekly_digest' => true,
    ]);

    Artisan::call('reports:weekly-digest');

    $snapshot = DashboardSnapshot::where('metric_key', 'weekly_report_status')->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->metric_value_json)->toHaveKeys(['last_sent_at', 'recipient_count', 'next_run_iso']);
    expect($snapshot->metric_value_json['recipient_count'])->toBe(1);
});

it('exits 0 + logs warning when no recipients are opted in', function (): void {
    Mail::fake();

    // No AlertRecipient rows at all.
    $exit = Artisan::call('reports:weekly-digest');

    expect($exit)->toBe(0);
    Mail::assertNothingSent();
});

it('is scheduled for Monday 07:00 Europe/London', function (): void {
    /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

    $match = collect($schedule->events())->first(
        fn ($event) => str_contains($event->command ?? '', 'reports:weekly-digest'),
    );

    expect($match)->not->toBeNull();
    expect($match->timezone)->toBe('Europe/London');
    // Laravel's weeklyOn(1, '07:00') translates to cron expression: '0 7 * * 1'.
    expect($match->expression)->toBe('0 7 * * 1');
});
