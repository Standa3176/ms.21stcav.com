<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Policies;

use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Models\User;

/**
 * Phase 6 Plan 01 — AutoCreateSkipRulePolicy (D-04 + Threat T-06-01-04).
 *
 * Role matrix:
 *   - viewAny/view: admin + pricing_manager (skip-rule catalogue is triage intel)
 *   - create/update/delete: admin only (governance — skip rules determine what
 *     enters the auto-create pipeline, which has cost + brand-reputation impact)
 *   - sales + read_only: denied (sales doesn't need to see vendor-exclusion policy;
 *     read_only is shop-floor visibility, not auto-create governance)
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (PolicyTemplateIntegrityTest catches leaks).
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/ProductAutoCreate/Policies/AutoCreateSkipRulePolicy.php
 */
final class AutoCreateSkipRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, AutoCreateSkipRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, AutoCreateSkipRule $rule): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, AutoCreateSkipRule $rule): bool
    {
        return $user->hasRole('admin');
    }
}
