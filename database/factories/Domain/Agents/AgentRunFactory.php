<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Agents;

use App\Domain\Agents\Models\AgentRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 8 Plan 01 — AgentRun factory.
 *
 * Default state mirrors a fresh `running` row: zero token counts + zero cost,
 * empty tool_calls, deterministic system_prompt_hash filler. Tests override
 * fields explicitly via `->create([...])` for status / finish_reason /
 * guardrail_failures coverage.
 *
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    protected $model = AgentRun::class;

    public function definition(): array
    {
        return [
            'kind' => 'echo',
            'status' => 'running',
            'triggering_suggestion_id' => null,
            'triggering_correlation_id' => fake()->uuid(),
            'system_prompt_hash' => str_repeat('a', 64),
            'tool_calls' => [],
            'agent_reasoning_summary' => null,
            'finish_reason' => null,
            'prompt_token_count' => 0,
            'completion_token_count' => 0,
            'cost_pence' => 0,
            'langfuse_trace_id' => null,
            'started_at' => now(),
            'completed_at' => null,
            'guardrail_failures' => null,
        ];
    }
}
