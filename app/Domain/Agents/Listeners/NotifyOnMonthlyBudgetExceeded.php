<?php

declare(strict_types=1);

namespace App\Domain\Agents\Listeners;

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Notifications\AgentAlertNotification;
use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 8 Plan 05 Task 4 (BLOCKER 2) — first-of-month dedup for the
 * monthly_budget_exceeded alert.
 *
 * Listens for AgentRunFailed; filters on `status === 'monthly_budget_blocked'`.
 * Cache key 'agents.alert.monthly.{YYYY-MM}' with 35-day TTL ensures the key
 * survives the entire month so subsequent in-month dispatches do NOT re-alert.
 */
final class NotifyOnMonthlyBudgetExceeded implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(AgentRunFailed $event): void
    {
        $statusValue = $event->run->status instanceof AgentRunStatus
            ? $event->run->status->value
            : (string) $event->run->status;

        if ($statusValue !== AgentRunStatus::MonthlyBudgetBlocked->value) {
            return;
        }

        $month = Carbon::now('Europe/London')->format('Y-m');
        $cacheKey = "agents.alert.monthly.{$month}";

        // 35 days = 60*60*24*35 (covers the entire month + buffer)
        if (! Cache::add($cacheKey, 1, 60 * 60 * 24 * 35)) {
            return;  // already alerted this month
        }

        $recipients = AlertRecipient::query()
            ->where('is_active', true)
            ->where('receives_agent_alerts', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AgentAlertNotification(
            kind: 'monthly_budget_exceeded',
            context: [
                'run_id' => $event->run->id,
                'month' => $month,
            ],
        ));
    }
}
