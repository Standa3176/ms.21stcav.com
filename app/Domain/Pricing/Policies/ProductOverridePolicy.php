<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Models\ProductOverride;
use App\Models\User;

/**
 * Phase 3 Plan 01 — ProductOverridePolicy (T-03-01-04 mitigation + Phase 1 D-02).
 *
 * Role matrix (same gating as PricingRulePolicy — overrides are just targeted
 * pricing rules):
 *   - admin + pricing_manager: viewAny/view/create/update/delete/restore/forceDelete
 *   - sales + read_only:       viewAny/view only
 *
 * Hand-written per Pitfall K + P2-H. DO NOT regenerate via shield:generate —
 * Plan 02-05's PolicyTemplateIntegrityTest catches the regression.
 */
final class ProductOverridePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, ProductOverride $override): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, ProductOverride $override): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, ProductOverride $override): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function restore(User $user, ProductOverride $override): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, ProductOverride $override): bool
    {
        return $user->hasRole('admin');
    }
}
