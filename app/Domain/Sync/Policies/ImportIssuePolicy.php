<?php

declare(strict_types=1);

namespace App\Domain\Sync\Policies;

use App\Domain\Sync\Models\ImportIssue;
use App\Models\User;

/**
 * Per Phase 1 D-02 + CONTEXT D-09: pricing_manager owns catalogue-health triage.
 *   - admin + pricing_manager: view/update/resolve
 *   - sales + read_only:       view-only
 *   - admin only:              delete (preserves triage audit trail)
 *
 * Pitfall P2-H: DO NOT regenerate via `shield:generate`. Plan 02-04 docs the
 * restore protocol; Plan 02-05 ships the grep guardrail.
 */
final class ImportIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, ImportIssue $issue): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // ImportIssues are produced by sync pipeline, not manually in UI.
        return false;
    }

    public function update(User $user, ImportIssue $issue): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, ImportIssue $issue): bool
    {
        return $user->hasRole('admin');
    }

    public function resolve(User $user, ImportIssue $issue): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }
}
