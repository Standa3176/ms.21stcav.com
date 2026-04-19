<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Models\User;

/**
 * Phase 4 Plan 01 — admin-only policy for CRM field mappings (CRM-06).
 *
 * Hand-written hasRole('admin') — see SuggestionPolicy docblock for
 * Shield-regeneration restore protocol (Pitfall K + P2-H).
 */
final class CrmFieldMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CrmFieldMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CrmFieldMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CrmFieldMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, CrmFieldMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, CrmFieldMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }
}
