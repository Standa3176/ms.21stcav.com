<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Models\User;

/**
 * Phase 4 Plan 01 — admin-only policy for the singleton pipeline settings
 * (D-05, D-07, CRM-07). Hand-written per SuggestionPolicy pattern — do NOT
 * regenerate via shield:generate (Pitfall K + P2-H).
 */
final class CrmPipelineSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CrmPipelineSetting $setting): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return false; // singleton — migration seeds; no new rows ever
    }

    public function update(User $user, CrmPipelineSetting $setting): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CrmPipelineSetting $setting): bool
    {
        return false; // singleton — never delete
    }

    public function restore(User $user, CrmPipelineSetting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, CrmPipelineSetting $setting): bool
    {
        return false;
    }
}
