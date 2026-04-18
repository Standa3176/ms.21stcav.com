<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Policies;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Models\User;

/**
 * T-05-07 admin-only gate on the AlertRecipient distribution list.
 *
 * Leaking these email addresses would expose ops staff to targeted phishing.
 * Every action is admin-only via `hasRole('admin')` — defence-in-depth on top
 * of Shield's permission assignment (Pitfall K).
 *
 * IMPORTANT: if a future plan runs `shield:generate --all --panel=admin`,
 * Shield will re-generate this policy with permission-based `$user->can(...)`
 * gates. Always restore the `hasRole('admin')` checks afterward — the role
 * check is belt-and-braces even if the RolePermissionSeeder's LIKE patterns
 * drift. See 01-04-SUMMARY.md deviation #3 for the same pattern applied to
 * SuggestionPolicy.
 */
class AlertRecipientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, AlertRecipient $r): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, AlertRecipient $r): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, AlertRecipient $r): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, AlertRecipient $r): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, AlertRecipient $r): bool
    {
        return $user->hasRole('admin');
    }
}
