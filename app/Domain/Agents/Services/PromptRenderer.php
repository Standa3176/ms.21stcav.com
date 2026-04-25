<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use Illuminate\Contracts\View\Factory as ViewFactory;
use RuntimeException;

/**
 * Phase 8 Plan 03 — system-prompt Blade renderer + sha256 hasher (CONTEXT
 * Claude's Discretion — prompts live at resources/views/agents/{kind}/system.blade.php).
 *
 * `agent_runs.system_prompt_hash` is sha256-of-rendered-prompt; lets ops
 * query "all runs that used prompt version X" by joining the hash to git
 * blame on the Blade file. No DB-stored prompts; no prompt-management UI
 * in v2.0 — git history + the hash column are the versioning surface.
 *
 * Rendering is on-demand per agent invocation so context variables (e.g.
 * triggering Suggestion's payload) interpolate cleanly at instantiation
 * time. A missing view fails loudly (RuntimeException) so a typo in the
 * agent's kind() never silently runs against an empty prompt.
 */
final class PromptRenderer
{
    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @param  string  $kind     Agent kind — maps to resources/views/agents/{kind}/system.blade.php.
     * @param  array<string, mixed>  $context  Variables to interpolate into the Blade view.
     * @return array{prompt: string, hash: string}
     */
    public function render(string $kind, array $context = []): array
    {
        $viewName = "agents.{$kind}.system";

        if (! $this->views->exists($viewName)) {
            throw new RuntimeException(
                "System prompt view missing: resources/views/agents/{$kind}/system.blade.php"
            );
        }

        $rendered = (string) $this->views->make($viewName, $context)->render();

        return [
            'prompt' => $rendered,
            'hash' => hash('sha256', $rendered),
        ];
    }
}
