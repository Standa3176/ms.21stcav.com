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
 * Phase 8 Plan 05 Task 4 (BLOCKER 2) — first-of-day-per-kind dedup for the
 * guardrail_blocked alert.
 *
 * Listens for AgentRunFailed; filters on `status === 'guardrail_blocked'`.
 * Extracts the violating guardrail class from the AgentRun's
 * `guardrail_failures[0].guardrail` JSON column. Cache key
 * 'agents.alert.guardrail.{class-basename}.{date}' with 25h TTL ensures
 * a fresh-day alert always lands on the SAME guardrail kind without
 * spamming repeats within a day.
 *
 * Different guardrails on the same day get separate cache keys, so each
 * fires once-per-day. Same guardrail same day = single alert.
 */
final class NotifyOnGuardrailBlocked implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(AgentRunFailed $event): void
    {
        $statusValue = $event->run->status instanceof AgentRunStatus
            ? $event->run->status->value
            : (string) $event->run->status;

        if ($statusValue !== AgentRunStatus::GuardrailBlocked->value) {
            return;
        }

        $failures = (array) ($event->run->guardrail_failures ?? []);
        $guardrailClass = (string) ($failures[0]['guardrail'] ?? 'unknown');
        $shortName = $guardrailClass !== 'unknown' ? class_basename($guardrailClass) : 'unknown';

        $date = Carbon::now('Europe/London')->format('Y-m-d');
        $cacheKey = "agents.alert.guardrail.{$shortName}.{$date}";

        // 25h TTL covers the calendar day + 1h buffer for late-night fires
        if (! Cache::add($cacheKey, 1, 60 * 60 * 25)) {
            return;
        }

        $recipients = AlertRecipient::query()
            ->where('is_active', true)
            ->where('receives_agent_alerts', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $kindValue = is_object($event->run->kind) && property_exists($event->run->kind, 'value')
            ? (string) $event->run->kind->value
            : (string) $event->run->kind;

        Notification::send($recipients, new AgentAlertNotification(
            kind: 'guardrail_blocked',
            context: [
                'run_id' => $event->run->id,
                'kind' => $kindValue,
                'guardrail' => $shortName,
            ],
        ));
    }
}
