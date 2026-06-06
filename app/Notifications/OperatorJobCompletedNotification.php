<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Quick task 260606-p4q — generic completion notification for long-running
 * operator jobs (auto-create pipeline, retry-missing-images, future ops
 * commands). Database channel only — surfaces via the bell-icon in the
 * Filament admin chrome (enabled by ->databaseNotifications() on the
 * AdminPanelProvider).
 *
 * Payload contract (consumed by Filament's bell-icon UI):
 *   - title  (string)        : short headline
 *   - body   (string)        : detail line (counts, level summary)
 *   - level  (string)        : success | danger | warning | info
 *   - url    (?string)       : optional deep-link the toast click navigates to
 *   - icon   (string)        : heroicon name derived from $level
 *
 * Designed as a GENERIC reusable notification — do NOT subclass per caller.
 * Future ops commands (PruneOrphanSuggestionsCommand, products:resync-to-woo,
 * etc.) wire `$user->notify(new OperatorJobCompletedNotification(...))`
 * directly. Test coverage at tests/Unit/Notifications/OperatorJobCompletedNotificationTest.php.
 *
 * ShouldQueue + database channel: persistence runs on the queue so the
 * dispatching job/command does not block on notification I/O. If the
 * notifications table is missing (e.g. prod has not yet migrated) the
 * caller-side try/catch absorbs — see RunAutoCreatePipelineJob::handle()
 * and RetryMissingImagesCommand::perform().
 */
final class OperatorJobCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $level = 'success',
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string|null>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'url' => $this->url,
            'icon' => $this->iconForLevel(),
        ];
    }

    private function iconForLevel(): string
    {
        return match ($this->level) {
            'success' => 'heroicon-o-check-circle',
            'danger' => 'heroicon-o-x-circle',
            'warning' => 'heroicon-o-exclamation-triangle',
            'info' => 'heroicon-o-information-circle',
            default => 'heroicon-o-bell',
        };
    }
}
