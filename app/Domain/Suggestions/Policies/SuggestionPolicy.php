<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Policies;

use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;

/**
 * Phase 1 policy — admin role only. Other roles get 403 on /admin/suggestions.
 *
 * Pitfall K compliance: `sales` should not see `margin_change` suggestions (privacy leak).
 *
 * NOTE: `shield:generate --all --panel=admin` produces a permission-based stub
 * (uses $user->can('view_any_suggestion') etc). Plan 04 Warning 9 mandates hasRole
 * checks as the second layer so that even if permission assignment drifts in Shield's
 * seeder LIKE queries, the admin-only gate holds. Do NOT regenerate this policy via
 * shield:generate without porting back the hasRole checks below.
 *
 * Belt-and-braces defence-in-depth:
 *   1. RolePermissionSeeder assigns suggestion permissions to admin only (first layer)
 *   2. This policy enforces hasRole('admin') regardless of permission state (second layer)
 *   3. ->authorize() on SuggestionResource approve/reject Actions enforces at POST (third)
 */
class SuggestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Suggestion $suggestion): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return false; // Suggestions are created by producers, never manually in Filament
    }

    public function update(User $user, Suggestion $suggestion): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Suggestion $suggestion): bool
    {
        return false; // append-only
    }

    public function deleteAny(User $user): bool
    {
        return false; // append-only
    }

    public function forceDelete(User $user, Suggestion $suggestion): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Suggestion $suggestion): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Suggestion $suggestion): bool
    {
        return $user->hasRole('admin');
    }

    public function reorder(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
