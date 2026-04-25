<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Policies;

use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;

/**
 * Phase 9 Plan 05 — CustomerGroupPolicy (TRDE-04 D-10).
 *
 * Role matrix:
 *   - admin + pricing_manager: viewAny/view/create/update/delete
 *   - sales:                    viewAny/view (view-only)
 *   - read_only:                NONE (intentionally locked out)
 *
 * Permission gating uses Spatie's `$user->can('*_customer_group')` strings —
 * the 5 perms are seeded by RolePermissionSeeder (Plan 05 Task 2). The policy
 * stays thin: each method asks "does the user hold the required permission?"
 * and Spatie answers via the role-permission map.
 *
 * DO NOT regenerate this policy via `php artisan shield:generate --all`.
 * Plan 05 Task 2 ships `php artisan shield:safe-regenerate
 * --allow-new=CustomerGroupPolicy` which scaffolds the new permissions
 * without clobbering this hand-written body. Subsequent runs drop the
 * --allow-new flag so existing PricingRulePolicy/etc. stay protected.
 */
final class CustomerGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_customer_group');
    }

    public function view(User $user, CustomerGroup $group): bool
    {
        return $user->can('view_customer_group');
    }

    public function create(User $user): bool
    {
        return $user->can('create_customer_group');
    }

    public function update(User $user, CustomerGroup $group): bool
    {
        return $user->can('update_customer_group');
    }

    public function delete(User $user, CustomerGroup $group): bool
    {
        return $user->can('delete_customer_group');
    }
}
