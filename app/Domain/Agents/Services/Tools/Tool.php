<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services\Tools;

/**
 * Phase 8 Plan 03 — abstract Tool base (AGNT-05).
 *
 * Every concrete tool subclass MUST satisfy three contracts:
 *   1. NAMING — name() starts with propose_/read_/search_
 *      (compile-time check via AgentToolsNamingTest;
 *       runtime check via ToolBus::assertNameAllowed).
 *   2. PRISM ADAPTATION — asPrismTool() returns a Prism\Prism\Tool instance
 *      so ClaudeClient->withTools() accepts it without further wrapping.
 *   3. SELF-DESCRIBING — description() drives the LLM's tool-use disambiguation
 *      (Pitfall A1 — clear "Use this when..." phrasing prevents prompt-injection
 *      tool loops).
 *
 * Plan 04 ships ReadHealthCheckTool (the EchoAgent's single tool) as the
 * canonical pattern. Phase 10 PricingAgent then ships propose_margin_change,
 * search_competitor_prices, etc.
 */
abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function asPrismTool(): \Prism\Prism\Tool;
}
