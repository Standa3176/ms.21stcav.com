<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Models\User;

/**
 * Phase 4 Plan 01 — admin-only policy for the Bitrix dedup ledger.
 *
 * Follows the Phase 1 SuggestionPolicy pattern — hardcoded hasRole('admin')
 * checks survive Shield drift (Pitfall K + P2-H). DO NOT regenerate via
 * `shield:generate`; Plan 02-05's PolicyTemplateIntegrityTest catches
 * placeholder stub regressions on every CI run.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/CRM/Policies/BitrixEntityMapPolicy.php
 */
final class BitrixEntityMapPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, BitrixEntityMap $map): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, BitrixEntityMap $map): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, BitrixEntityMap $map): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, BitrixEntityMap $map): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, BitrixEntityMap $map): bool
    {
        return $user->hasRole('admin');
    }
}
