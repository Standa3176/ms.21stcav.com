<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Notifiables;

use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;

/**
 * D-11 single distribution: every failed-job alert routes to every active
 * AlertRecipient. No severity splits in v1 — revisit after a month of real
 * traffic volume.
 *
 * Route resolution happens at notification-dispatch time so recipient list
 * changes in the Filament UI take effect immediately (no cache).
 *
 * D-10: email-only — routeNotificationForSlack() explicitly returns null
 * even if config drifts and enables a slack channel later.
 *
 * Pitfall M defensive log: if the recipient list resolves empty (despite
 * seeder seeding ops@meetingstore.co.uk), emit a warning so we see the
 * silent-outage risk in logs.
 */
final class AlertDistribution
{
    use Notifiable;

    /**
     * Stable key for Laravel's Notification system (expected by NotificationFake
     * and message-bag keying in the notification pipeline). AlertDistribution is
     * a singleton-shaped notifiable — there's only one distribution list — so the
     * key is a constant.
     */
    public function getKey(): string
    {
        return 'alert-distribution';
    }

    /** @return array<string, string>  map of email => name for Laravel mail routing */
    public function routeNotificationForMail(): array
    {
        $routes = AlertRecipient::where('is_active', true)
            ->pluck('name', 'email')
            ->all();

        if (empty($routes)) {
            // Pitfall M safety net — should not fire because the seeder seeds a fallback row.
            Log::warning('AlertDistribution: no active AlertRecipient rows — failed-job alerts will silently drop. Run AlertRecipientSeeder.');
        }

        return $routes;
    }

    public function routeNotificationForSlack(): ?string
    {
        return null;
    }
}
