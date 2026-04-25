<?php

declare(strict_types=1);

namespace App\Domain\Agents\Listeners;

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Notifications\AgentAlertNotification;
use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 8 Plan 05 Task 4 (BLOCKER 2) — generic AgentRunFailed listener.
 *
 * 5-min cache-key dedup matches v1 ThrottledFailedJobNotifier pattern. When
 * the failed run's status is monthly_budget_blocked OR guardrail_blocked,
 * the dedicated listener (NotifyOnMonthlyBudgetExceeded / NotifyOnGuardrailBlocked)
 * handles it; we early-exit here to avoid double-notify.
 */
final class NotifyOnAgentRunFailed implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(AgentRunFailed $event): void
    {
        // Defer to dedicated listeners for the specific failure kinds.
        $statusValue = $event->run->status instanceof AgentRunStatus
            ? $event->run->status->value
            : (string) $event->run->status;

        if (in_array($statusValue, [
            AgentRunStatus::MonthlyBudgetBlocked->value,
            AgentRunStatus::GuardrailBlocked->value,
        ], true)) {
            return;
        }

        $kindValue = is_object($event->run->kind) && property_exists($event->run->kind, 'value')
            ? (string) $event->run->kind->value
            : (string) $event->run->kind;

        $bucket = (int) floor(time() / 300);  // 5-min bucket
        $cacheKey = "agents.alert.failed.{$kindValue}.{$bucket}";

        // 10-min TTL spans bucket edges safely
        if (! Cache::add($cacheKey, 1, 600)) {
            return;  // dedup hit
        }

        $recipients = AlertRecipient::query()
            ->where('is_active', true)
            ->where('receives_agent_alerts', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AgentAlertNotification(
            kind: 'agent_run_failed',
            context: [
                'kind' => $kindValue,
                'run_id' => $event->run->id,
                'reason' => mb_substr((string) ($event->run->agent_reasoning_summary ?? $event->exception->getMessage()), 0, 256),
            ],
        ));
    }
}
