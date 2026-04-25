<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

use App\Domain\TradePricing\Models\CustomerGroup;

/**
 * Phase 9 Plan 04 Task 2 — D-07 role -> CustomerGroup mapper.
 *
 * Reads config('b2b.role_to_group_map') (4 entries by default). Returns the
 * matching CustomerGroup (or its id via mapToGroupId) or null when the Woo
 * role is not whitelisted — unknown roles fall through to retail
 * (null = no group).
 *
 * Reads config every call so operator can hot-swap via .env / config cache
 * clear without restarting workers. Filters on is_active=true so a
 * deactivated group cannot accidentally re-enter the trade-pricing path
 * via a stale Woo role.
 *
 * Used by:
 *   - UpdateCustomerGroupOnUserRoleChange listener (Plan 09-04 Task 3)
 *   - b2b:backfill-customer-groups command (Plan 09-06 Task 1)
 */
final class RoleToGroupMapper
{
    /**
     * Resolve a Woo role string to the matching CustomerGroup model.
     *
     * Returns null when:
     *   - $role is null or empty string
     *   - $role is not present in config('b2b.role_to_group_map')
     *   - the mapped slug exists but is_active=false
     *   - the mapped slug does not exist in customer_groups
     */
    public function resolve(?string $role): ?CustomerGroup
    {
        if ($role === null || $role === '') {
            return null;
        }

        $map = (array) config('b2b.role_to_group_map', []);
        $slug = $map[$role] ?? null;

        if ($slug === null) {
            return null;
        }

        return CustomerGroup::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Convenience for backfill / listener call sites that prefer the integer
     * ID instead of the model instance. Returns null with the same fall-through
     * semantics as resolve().
     */
    public function mapToGroupId(?string $role): ?int
    {
        return $this->resolve($role)?->id;
    }
}
