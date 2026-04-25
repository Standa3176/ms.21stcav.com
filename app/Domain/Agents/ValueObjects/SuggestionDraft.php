<?php

declare(strict_types=1);

namespace App\Domain\Agents\ValueObjects;

/**
 * Phase 8 Plan 03 — agent's "I want to propose this Suggestion" intent (AGNT-01).
 *
 * Produced inside `RunsAsAgent::execute()`; consumed by Plan 04's RunAgentJob
 * which hands it to AgentSuggestionWriter for persistence. Agents do NOT
 * write to the suggestions table directly — they emit drafts.
 *
 * Readonly so once produced the agent can't mutate it before the framework
 * picks it up (defence-in-depth on top of the architecture-test grep).
 */
final readonly class SuggestionDraft
{
    /**
     * @param  string  $kind      Suggestion::kind ('echo_health' / 'margin_change' / etc).
     * @param  array<string, mixed>  $payload   Apply-shaped data (what the applier will execute).
     * @param  array<string, mixed>  $evidence  Reasoning + tool-call summary for admin review UI.
     */
    public function __construct(
        public string $kind,
        public array $payload,
        public array $evidence,
    ) {}
}
