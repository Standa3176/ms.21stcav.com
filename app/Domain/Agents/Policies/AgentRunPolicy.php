<?php

declare(strict_types=1);

namespace App\Domain\Agents\Policies;

use App\Domain\Agents\Models\AgentRun;
use App\Models\User;

/**
 * Phase 8 Plan 01 — admin-only AgentRun viewer (D-06 + Pitfall P5-F).
 *
 * AgentRuns are produced by the framework (Plan 04 RunAgentJob) and never
 * mutated by an admin — every method except viewAny/view returns false
 * unconditionally. Even super-admins cannot edit AgentRun via Filament.
 *
 * Pitfall K + P5-F: hand-written `hasRole('admin')` is the load-bearing
 * second layer atop Shield's permission assignment. Do NOT regenerate
 * via `shield:generate` without porting back hasRole — Plan 02-04 promoted
 * `PolicyTemplateIntegrityTest` to the Architecture suite specifically to
 * catch placeholder leaks. Plan 08-04 will register Shield permissions
 * (`view_any_agent_run`, `view_agent_run`) but those are belt; this
 * policy is the braces.
 *
 * Filament Resource for AgentRun ships in Plan 08-04 (with the EchoAgent
 * end-to-end demo). This policy gate registers in Plan 08-01 so the
 * Architecture-test floor in `PolicyTemplateIntegrityTest` (26 → 27) is
 * covered the moment the framework keystone lands.
 */
final class AgentRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, AgentRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return false; // AgentRuns are framework-produced, never admin-created
    }

    public function update(User $user, AgentRun $run): bool
    {
        return false; // append-only — only RunAgentJob mutates after creation
    }

    public function delete(User $user, AgentRun $run): bool
    {
        return false; // 5y retention enforced by agents:prune-archive (Plan 05)
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AgentRun $run): bool
    {
        return false;
    }

    public function forceDelete(User $user, AgentRun $run): bool
    {
        return false;
    }
}
