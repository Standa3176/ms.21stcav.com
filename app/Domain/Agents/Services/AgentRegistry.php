<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Contracts\RunsAsAgent;
use RuntimeException;

/**
 * Phase 8 Plan 03 — kind→class agent lookup (AGNT-02).
 *
 * Singleton bound in AppServiceProvider::register(). Producer plans register
 * their agent class via `afterResolving` (Plan 04 ships EchoAgent; Phase 10
 * adds PricingAgent; Phase 12 adds SeoAgent; etc).
 *
 * Mirrors v1's SuggestionApplierResolver shape verbatim — same in-memory
 * array, same throw-on-unknown semantics, same `app($class)` resolution.
 * Reusing the proven shape keeps cognitive load low for v1 maintainers
 * picking up Agents-domain code for the first time.
 */
final class AgentRegistry
{
    /** @var array<string, class-string<RunsAsAgent>> */
    private array $registry = [];

    public function register(string $kind, string $agentClass): void
    {
        $this->registry[$kind] = $agentClass;
    }

    public function resolve(string $kind): RunsAsAgent
    {
        $class = $this->registry[$kind] ?? throw new RuntimeException(
            "No agent registered for kind: {$kind}"
        );

        return app($class);
    }

    /** @return array<string, class-string<RunsAsAgent>> */
    public function registered(): array
    {
        return $this->registry;
    }
}
