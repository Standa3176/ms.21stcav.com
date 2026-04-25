<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

/**
 * Phase 8 Plan 03 — thrown by ToolBus on two distinct violations (AGNT-05):
 *
 *   1. NAMING — Tool::name() does not start with propose_/read_/search_.
 *      AgentToolsNamingTest enforces this at compile time; ToolBus enforces at
 *      runtime so a dynamically-registered tool can't smuggle a forbidden
 *      prefix past the architecture test.
 *
 *   2. ALLOW-LIST — agent attempts to invoke a tool name not in its
 *      declared `tools()` array. Defends against prompt-injection coercing
 *      the LLM to call a tool the agent author did not explicitly authorise.
 *
 * Plan 04 RunAgentJob's catch-block records the violation onto AgentRun
 * (status=Failed; error_message includes the offending tool name).
 */
final class UnauthorisedToolException extends \RuntimeException
{
}
