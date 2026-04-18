<?php

declare(strict_types=1);

namespace App\Domain\Sync\Policies;

use App\Domain\Sync\Models\SyncRun;
use App\Models\User;

/**
 * Per Phase 1 D-02 role split: view for all 4 roles (sync status visibility
 * is operational intel every role needs); retry is admin-only (potentially
 * expensive re-run). Creation + update + delete are disabled at the UI layer
 * because SyncRun rows are producer-owned (orchestrator command writes them).
 *
 * Pitfall P2-H: DO NOT regenerate via `shield:generate`. Plan 02-04 docs the
 * restore protocol; Plan 02-05 ships the grep guardrail.
 */
final class SyncRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, SyncRun $run): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // SyncRuns are created ONLY by the orchestrator command; no UI creation.
        return false;
    }

    public function update(User $user, SyncRun $run): bool
    {
        // State transitions are driven by the state machine; no ad-hoc UI edits.
        return false;
    }

    public function delete(User $user, SyncRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function retry(User $user, SyncRun $run): bool
    {
        // Per Pitfall K pattern: admin-only for expensive operations.
        return $user->hasRole('admin');
    }
}
