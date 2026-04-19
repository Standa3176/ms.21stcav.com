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
 *
 * Plan 04-03 D-12: the constructor accepts an optional `onlyReceiving` column
 * name so CRM-alert dispatches can filter to `receives_crm_alerts=true`.
 * Default (null) preserves legacy behaviour (active recipients for failed-job
 * alerts). A non-null value additionally filters on that boolean column.
 */
final class AlertDistribution
{
    use Notifiable;

    public function __construct(
        private readonly ?string $onlyReceiving = null,
    ) {
    }

    /**
     * Stable key for Laravel's Notification system (expected by NotificationFake
     * and message-bag keying in the notification pipeline). AlertDistribution is
     * a singleton-shaped notifiable — there's only one distribution list — so the
     * key is a constant (per-channel suffix for CRM alerts so fake assertions
     * can distinguish the two distribution flavours).
     */
    public function getKey(): string
    {
        return $this->onlyReceiving === null
            ? 'alert-distribution'
            : 'alert-distribution:'.$this->onlyReceiving;
    }

    /** @return array<string, string>  map of email => name for Laravel mail routing */
    public function routeNotificationForMail(): array
    {
        $query = AlertRecipient::where('is_active', true);

        if ($this->onlyReceiving !== null) {
            $query->where($this->onlyReceiving, true);
        }

        $routes = $query->pluck('name', 'email')->all();

        if (empty($routes)) {
            // Pitfall M safety net — should not fire because the seeder seeds a fallback row.
            Log::warning('AlertDistribution: no active AlertRecipient rows — alerts will silently drop. Run AlertRecipientSeeder.', [
                'only_receiving' => $this->onlyReceiving,
            ]);
        }

        return $routes;
    }

    public function routeNotificationForSlack(): ?string
    {
        return null;
    }
}
