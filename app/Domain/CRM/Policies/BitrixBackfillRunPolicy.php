<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Models\User;

/**
 * Phase 4 Plan 01 — admin-only policy for backfill run history (CRM-10).
 * Hand-written per SuggestionPolicy pattern — do NOT regenerate via
 * shield:generate (Pitfall K + P2-H).
 */
final class BitrixBackfillRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, BitrixBackfillRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, BitrixBackfillRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, BitrixBackfillRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, BitrixBackfillRun $run): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, BitrixBackfillRun $run): bool
    {
        return $user->hasRole('admin');
    }
}
