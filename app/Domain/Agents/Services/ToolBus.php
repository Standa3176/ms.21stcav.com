<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Exceptions\UnauthorisedToolException;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\Tools\Tool;

/**
 * Phase 8 Plan 03 (AGNT-05) — per-agent tool registry + naming-convention enforcer.
 *
 * Two responsibilities:
 *   1. Naming gate — every tool's name() MUST start propose_/read_/search_.
 *      AgentToolsNamingTest catches at compile time; ToolBus catches at runtime
 *      so a dynamically-built tool can't smuggle a forbidden prefix past the
 *      architecture suite.
 *   2. Per-agent allow-list — Plan 04's RunAgentJob hands an agent's declared
 *      tools() array to buildPrismTools(). Any other tool name surfacing in a
 *      Prism response (via prompt injection or future LLM creativity) gets
 *      rejected by Prism itself because it never made the allow-list passed
 *      to ClaudeClient::generate(...).
 *
 * Tool-call recording (AgentRun.tool_calls JSON) is finalised in Plan 04
 * RunAgentJob by walking ClaudeResponse->steps — each Prism step exposes its
 * own tool calls + results inline. Recording from the response is more
 * reliable than instrumenting the callable. ToolBus::truncate() exposes the
 * 4KB cap utility so RunAgentJob can apply it before persisting onto the
 * tool_calls JSON column (CONTEXT D-06).
 */
final class ToolBus
{
    /** @var array<int, string> */
    public const ALLOWED_PREFIXES = ['propose_', 'read_', 'search_'];

    public const MAX_INPUT_BYTES = 4096;

    public const MAX_OUTPUT_BYTES = 4096;

    /**
     * Build the array of Prism Tool objects for an agent run.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, \Prism\Prism\Tool>
     */
    public function buildPrismTools(array $tools, AgentRun $run): array
    {
        $built = [];
        foreach ($tools as $tool) {
            $this->assertNameAllowed($tool);
            $built[] = $tool->asPrismTool();
        }

        return $built;
    }

    /**
     * Enforce the AGNT-05 naming convention at runtime.
     *
     * @throws UnauthorisedToolException  on prefix mismatch
     */
    public function assertNameAllowed(Tool $tool): void
    {
        $name = $tool->name();
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return;
            }
        }

        throw new UnauthorisedToolException(
            "Tool '{$name}' does not start with propose_/read_/search_"
        );
    }

    /**
     * Truncation utility for RunAgentJob — applies 4KB caps to tool inputs/outputs
     * before persisting onto AgentRun.tool_calls JSON. Long arrays are converted
     * to a `_truncated` marker so the JSON column doesn't blow past the schema cap.
     *
     * @param  string|array<mixed, mixed>  $value
     * @return string|array<mixed, mixed>
     */
    public function truncate(string|array $value, int $max): string|array
    {
        if (is_array($value)) {
            $json = json_encode($value);
            if ($json !== false && strlen($json) > $max) {
                return [
                    '_truncated' => true,
                    '_size_bytes' => strlen($json),
                    '_preview' => substr($json, 0, $max),
                ];
            }

            return $value;
        }

        return strlen($value) > $max
            ? substr($value, 0, $max).'...[truncated]'
            : $value;
    }
}
