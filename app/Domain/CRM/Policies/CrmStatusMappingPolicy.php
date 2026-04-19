<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\CrmStatusMapping;
use App\Models\User;

/**
 * Phase 4 Plan 01 — admin-only policy for the Woo-status → Bitrix-stage map
 * (D-06, CRM-07). Hand-written per SuggestionPolicy pattern — do NOT
 * regenerate via shield:generate (Pitfall K + P2-H).
 */
final class CrmStatusMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CrmStatusMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CrmStatusMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CrmStatusMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, CrmStatusMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, CrmStatusMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }
}
