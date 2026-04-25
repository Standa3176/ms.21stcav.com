<?php

declare(strict_types=1);

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Listeners\NotifyOnAgentRunFailed;
use App\Domain\Agents\Listeners\NotifyOnGuardrailBlocked;
use App\Domain\Agents\Listeners\NotifyOnMonthlyBudgetExceeded;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Notifications\AgentAlertNotification;
use App\Domain\Alerting\Models\AlertRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 05 Task 4 — AgentNotificationTest (BLOCKER 2)
|--------------------------------------------------------------------------
|
| Verifies the 4 plan-spec behaviours:
|   1. AgentRunFailed dispatches AgentAlertNotification to receives_agent_alerts=true ONLY
|   2. 5-min dedup for agent_run_failed kind
|   3. First-of-month dedup for monthly_budget_exceeded
|   4. First-of-day-per-kind dedup for guardrail_blocked
*/

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
});

it('dispatches AgentAlertNotification only to recipients with receives_agent_alerts=true (Test 1)', function (): void {
    $optedIn = AlertRecipient::create([
        'email' => 'opt-in@example.com',
        'name' => 'Opt In',
        'is_active' => true,
        'receives_agent_alerts' => true,
    ]);
    $optedOut = AlertRecipient::create([
        'email' => 'opt-out@example.com',
        'name' => 'Opt Out',
        'is_active' => true,
        'receives_agent_alerts' => false,
    ]);

    $run = AgentRun::factory()->create([
        'kind' => 'echo',
        'status' => AgentRunStatus::Failed->value,
        'completed_at' => now(),
    ]);

    $listener = app(NotifyOnAgentRunFailed::class);
    $listener->handle(new AgentRunFailed($run, new \RuntimeException('boom')));

    Notification::assertSentTo($optedIn, AgentAlertNotification::class);
    Notification::assertNotSentTo($optedOut, AgentAlertNotification::class);
});

it('5-min cache dedup prevents repeat agent_run_failed notifications (Test 2)', function (): void {
    AlertRecipient::create([
        'email' => 'ops@example.com',
        'is_active' => true,
        'receives_agent_alerts' => true,
    ]);

    $run = AgentRun::factory()->create([
        'kind' => 'echo',
        'status' => AgentRunStatus::Failed->value,
        'completed_at' => now(),
    ]);

    $listener = app(NotifyOnAgentRunFailed::class);

    // First fire — notification dispatched
    $listener->handle(new AgentRunFailed($run, new \RuntimeException('boom-1')));
    Notification::assertSentTimes(AgentAlertNotification::class, 1);

    // Second fire within the same 5-min bucket — dedup kicks in
    $listener->handle(new AgentRunFailed($run, new \RuntimeException('boom-2')));
    Notification::assertSentTimes(AgentAlertNotification::class, 1);
});

it('first-of-month dedup for monthly_budget_exceeded (Test 3)', function (): void {
    AlertRecipient::create([
        'email' => 'ops@example.com',
        'is_active' => true,
        'receives_agent_alerts' => true,
    ]);

    $run = AgentRun::factory()->create([
        'kind' => 'echo',
        'status' => AgentRunStatus::MonthlyBudgetBlocked->value,
        'completed_at' => now(),
    ]);

    $listener = app(NotifyOnMonthlyBudgetExceeded::class);

    // First fire of the month — notification dispatched
    $listener->handle(new AgentRunFailed($run, new \RuntimeException('budget')));
    Notification::assertSentTimes(AgentAlertNotification::class, 1);

    // Second fire same month — dedup blocks
    $listener->handle(new AgentRunFailed($run, new \RuntimeException('budget-again')));
    Notification::assertSentTimes(AgentAlertNotification::class, 1);

    // Verify the dispatched notification carried kind='monthly_budget_exceeded'
    Notification::assertSentTo(
        AlertRecipient::where('email', 'ops@example.com')->first(),
        function (AgentAlertNotification $n) {
            return $n->kind === 'monthly_budget_exceeded';
        }
    );
});

it('first-of-day-per-kind dedup for guardrail_blocked (Test 4)', function (): void {
    AlertRecipient::create([
        'email' => 'ops@example.com',
        'is_active' => true,
        'receives_agent_alerts' => true,
    ]);

    Carbon::setTestNow('2026-04-25 10:00:00', 'Europe/London');

    $runA = AgentRun::factory()->create([
        'kind' => 'echo',
        'status' => AgentRunStatus::GuardrailBlocked->value,
        'completed_at' => now(),
        'guardrail_failures' => [[
            'guardrail' => 'App\\Domain\\Agents\\Guardrails\\OutboundRegexFilterGuardrail',
            'message' => 'forbidden pattern',
            'when' => 'post',
            'occurred_at' => now()->toIso8601String(),
        ]],
    ]);

    $runB = AgentRun::factory()->create([
        'kind' => 'echo',
        'status' => AgentRunStatus::GuardrailBlocked->value,
        'completed_at' => now(),
        'guardrail_failures' => [[
            'guardrail' => 'App\\Domain\\Agents\\Guardrails\\PromptInjectionXmlFenceGuardrail',
            'message' => 'injection blocked',
            'when' => 'pre',
            'occurred_at' => now()->toIso8601String(),
        ]],
    ]);

    $listener = app(NotifyOnGuardrailBlocked::class);

    // First fire of the day — OutboundRegexFilter
    $listener->handle(new AgentRunFailed($runA, new \RuntimeException('guardrail-A')));
    Notification::assertSentTimes(AgentAlertNotification::class, 1);

    // Second fire — DIFFERENT guardrail kind same day → fires separately
    $listener->handle(new AgentRunFailed($runB, new \RuntimeException('guardrail-B')));
    Notification::assertSentTimes(AgentAlertNotification::class, 2);

    // Third fire — REPEAT OutboundRegexFilter same day → dedup blocks
    $listener->handle(new AgentRunFailed($runA, new \RuntimeException('guardrail-A-again')));
    Notification::assertSentTimes(AgentAlertNotification::class, 2);

    Carbon::setTestNow();
});
