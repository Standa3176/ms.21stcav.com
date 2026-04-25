<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services\Tools;

use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 8 Plan 04 — EchoAgent's only tool (AGNT-12 framework smoke test).
 *
 * Returns three primitives that confirm the framework is wired:
 *   - timestamp   — ISO-8601 UTC, proves the call landed
 *   - git_sha     — short HEAD SHA, proves the deployed commit identity
 *                   (graceful "unknown" fallback for non-git environments)
 *   - app_version — config('app.version'); falls back to 'v2.0' string
 *
 * All three are side-effect-free reads — nothing here touches any v1 table or
 * external API. Naming `read_health_check` satisfies AGNT-05 (read_ prefix —
 * both AgentToolsNamingTest at compile time + ToolBus::assertNameAllowed at
 * runtime accept it).
 *
 * Per CONTEXT D-09 (T-08-04-06 mitigation): no env vars / secrets surfaced.
 * git_sha is truncated to 12 chars (already public if the repo is public, and
 * sufficient for deploy identification).
 */
final class ReadHealthCheckTool extends Tool
{
    public function name(): string
    {
        return 'read_health_check';
    }

    public function description(): string
    {
        return 'Returns the current timestamp, git SHA and app version. Call once to confirm framework health. Do NOT retry.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->using(fn (): string => json_encode([
                'timestamp' => now()->toIso8601String(),
                'git_sha' => $this->resolveGitSha(),
                'app_version' => (string) config('app.version', 'v2.0'),
            ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve a 12-char git SHA from HEAD. Falls back to 'unknown' if the
     * binary is missing, the call fails, or we're in a non-git environment
     * (e.g. CI image with .git stripped). Never throws — health check must
     * not itself be a source of failure.
     */
    private function resolveGitSha(): string
    {
        // 2>&1 silences stderr "fatal: not a git repository" leaking onto
        // the JSON return value. shell_exec returns null on failure → cast
        // to string, trim, fall back to 'unknown' on empty.
        $sha = trim((string) @shell_exec('git rev-parse HEAD 2>&1'));

        // Defensive: error output (e.g. "fatal: ...") would still arrive
        // here. A real SHA is exactly 40 hex chars; anything else means
        // git failed and we return the safe placeholder.
        if ($sha === '' || ! preg_match('/^[0-9a-f]{40}$/i', $sha)) {
            return 'unknown';
        }

        return substr($sha, 0, 12);
    }
}
