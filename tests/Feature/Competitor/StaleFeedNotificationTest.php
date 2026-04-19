<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Notifications\StaleFeedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Phase 5 Plan 04b Task 2 — StaleFeedNotification content shape + channel.
 */
it('uses the mail channel only', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'note-mail', 'name' => 'Mail Acme']);
    $notification = new StaleFeedNotification($competitor, 50);

    $recipient = AlertRecipient::create([
        'email' => 'mail-channel@example.com',
        'name' => 'MC',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    expect($notification->via($recipient))->toBe(['mail']);
});

it('builds a MailMessage with subject + body lines referring to the competitor', function (): void {
    $competitor = Competitor::factory()->create([
        'slug' => 'note-body',
        'name' => 'Body Corp',
        'last_ingest_at' => now()->subHours(72),
    ]);

    $notification = new StaleFeedNotification($competitor, 72);
    $recipient = AlertRecipient::create([
        'email' => 'body@example.com',
        'name' => 'Body',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    $mail = $notification->toMail($recipient);
    expect($mail)->toBeInstanceOf(MailMessage::class);

    $subject = $mail->subject;
    expect($subject)->toContain('Body Corp');
    expect($subject)->toContain('Stale competitor feed');

    $rendered = json_encode($mail->toArray());
    expect($rendered)->toContain('72');
    expect($rendered)->toContain('Body Corp');
    expect($rendered)->toContain('competitor-ingest-runs');
});

it('renders "No ingest recorded" when hoursStale is null', function (): void {
    $competitor = Competitor::factory()->create([
        'slug' => 'note-null',
        'name' => 'Never Sighted',
        'last_ingest_at' => null,
    ]);

    $notification = new StaleFeedNotification($competitor, null);
    $recipient = AlertRecipient::create([
        'email' => 'null-stale@example.com',
        'name' => 'NS',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    $mail = $notification->toMail($recipient);
    $rendered = json_encode($mail->toArray());
    expect($rendered)->toContain('No ingest recorded');
});

it('action URL filters by competitor_id on /admin/competitor-ingest-runs', function (): void {
    $competitor = Competitor::factory()->create([
        'slug' => 'note-url',
        'name' => 'URL Corp',
    ]);

    $notification = new StaleFeedNotification($competitor, 50);
    $recipient = AlertRecipient::create([
        'email' => 'url@example.com',
        'name' => 'URL',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    $mail = $notification->toMail($recipient);
    $arr = $mail->toArray();
    expect($arr['actionUrl'] ?? '')->toContain('/admin/competitor-ingest-runs');
    expect($arr['actionUrl'] ?? '')->toContain((string) $competitor->id);
});
