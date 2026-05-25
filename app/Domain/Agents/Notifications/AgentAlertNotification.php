<?php

declare(strict_types=1);

namespace App\Domain\Agents\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 8 Plan 05 Task 4 (BLOCKER 2 — plan-checker iter 1).
 *
 * Single Notification with `via=['mail', 'database']` and a templated body
 * per kind:
 *   - 'monthly_budget_exceeded'
 *   - 'agent_run_failed'
 *   - 'guardrail_blocked'
 *
 * Database channel emits a row to the notifications table for the Filament
 * Notification Centre (Phase 7 NotificationCentrePage already polls this).
 *
 * Queued on `default` (mail rendering does NOT need agents-supervisor — that
 * queue is reserved for actual Anthropic dispatches).
 */
final class AgentAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $context,
    ) {
        // PHP 8.4 trait-collision guard — set the queue via onQueue() in the
        // constructor; NEVER `public string $queue` (it collides with the
        // Queueable trait's untyped $queue property and fatals on PHP 8.4).
        $this->onQueue('default');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->kind) {
            'monthly_budget_exceeded' => '[MeetingStore Ops] Agent monthly budget reached',
            'agent_run_failed' => '[MeetingStore Ops] Agent run failed',
            'guardrail_blocked' => '[MeetingStore Ops] Agent guardrail blocked a run',
            default => '[MeetingStore Ops] Agent alert',
        };

        $line = match ($this->kind) {
            'monthly_budget_exceeded' => 'The monthly agent budget has been reached. New agent dispatches are blocked until next month.',
            'agent_run_failed' => sprintf(
                'Agent run failed: kind=%s; reason=%s',
                (string) ($this->context['kind'] ?? 'unknown'),
                (string) ($this->context['reason'] ?? '(no reason captured)'),
            ),
            'guardrail_blocked' => sprintf(
                'Guardrail blocked an agent run: guardrail=%s; kind=%s',
                (string) ($this->context['guardrail'] ?? 'unknown'),
                (string) ($this->context['kind'] ?? 'unknown'),
            ),
            default => 'See ops dashboard for details.',
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('View agent runs', url('/admin/agent-runs'));
    }

    /**
     * Database channel payload — surfaced by Filament Notification Centre.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'context' => $this->context,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
