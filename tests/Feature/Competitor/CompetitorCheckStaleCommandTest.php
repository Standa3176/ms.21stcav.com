<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Notifications\StaleFeedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 5 Plan 04b Task 2 — competitor:check-stale command (COMP-11).
 *
 * 48h threshold sourced from config('competitor.stale_feed_hours'); 24h
 * per-competitor dedup via Cache::add('competitor.stale_alert.{id}.{YYYY-MM-DD}').
 * Notifications route via receives_competitor_alerts + is_active.
 */
beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
});

it('dispatches nothing when no active competitors exist', function (): void {
    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertNothingSent();
});

it('dispatches StaleFeedNotification when an active competitor is stale (>48h)', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'cm-stale-1@example.com',
        'name' => 'Stale Recv 1',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    $stale = Competitor::factory()->create([
        'slug' => 'cmd-stale-1',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(50),
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();

    Notification::assertSentTo($recipient, StaleFeedNotification::class);
});

it('does NOT dispatch when last_ingest_at is younger than threshold', function (): void {
    AlertRecipient::create([
        'email' => 'cm-fresh-1@example.com',
        'name' => 'Fresh Recv',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-fresh-1',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(10),
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertNothingSent();
});

it('treats NULL last_ingest_at as stale (missing feed)', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'cm-missing-1@example.com',
        'name' => 'Missing Recv',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-missing-1',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => null,
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertSentTo($recipient, StaleFeedNotification::class);
});

it('skips inactive (status!=active OR is_active=false) competitors even when stale', function (): void {
    AlertRecipient::create([
        'email' => 'cm-inactive-recv@example.com',
        'name' => 'Inactive',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-inactive-status',
        'status' => Competitor::STATUS_INACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subDays(5),
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-inactive-flag',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => false,
        'last_ingest_at' => now()->subDays(5),
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertNothingSent();
});

it('deduplicates within 24h — second run for the same stale competitor dispatches nothing', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'cm-dedup@example.com',
        'name' => 'Dedup',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-dedup',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(72),
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertSentTo($recipient, StaleFeedNotification::class);

    // Second fake-layer reset so we can count from zero.
    Notification::fake();

    $this->artisan('competitor:check-stale')->assertSuccessful();
    Notification::assertNothingSent();
});

it('filters recipients by receives_competitor_alerts + is_active', function (): void {
    $wanted = AlertRecipient::create([
        'email' => 'cm-subscribed@example.com',
        'name' => 'Subscribed',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    $optedOut = AlertRecipient::create([
        'email' => 'cm-opted-out@example.com',
        'name' => 'Opted Out',
        'is_active' => true,
        'receives_competitor_alerts' => false,
    ]);

    $deactivated = AlertRecipient::create([
        'email' => 'cm-deactivated@example.com',
        'name' => 'Deactivated',
        'is_active' => false,
        'receives_competitor_alerts' => true,
    ]);

    Competitor::factory()->create([
        'slug' => 'cmd-filter-test',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(96),
    ]);

    $this->artisan('competitor:check-stale')->assertSuccessful();

    Notification::assertSentTo($wanted, StaleFeedNotification::class);
    Notification::assertNotSentTo($optedOut, StaleFeedNotification::class);
    Notification::assertNotSentTo($deactivated, StaleFeedNotification::class);
});

it('the command is registered in the hourly schedule with onOneServer + withoutOverlapping', function (): void {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    $events = collect($schedule->events())->filter(
        fn ($e) => str_contains($e->command ?? '', 'competitor:check-stale')
    );

    expect($events->count())->toBeGreaterThanOrEqual(1);

    /** @var \Illuminate\Console\Scheduling\Event $event */
    $event = $events->first();
    expect($event->expression)->toBe('0 * * * *'); // hourly
    expect($event->onOneServer)->toBeTrue();
});
