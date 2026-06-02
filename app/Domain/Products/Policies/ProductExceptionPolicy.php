<?php

declare(strict_types=1);

namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\ProductException;
use App\Models\User;

/**
 * RBAC mirrors ProductPolicy:
 *   - admin + pricing_manager: full CRUD
 *   - sales + read_only:       view-only (visibility into what's pinned)
 *   - admin only:              delete (defensive — exceptions affect Woo
 *     publish status, so destructive removal is admin-scope)
 *
 * Pattern-locked per Pitfall P5-F: hand-written hasRole checks; do NOT
 * regenerate via shield:generate (would replace with permission stubs
 * and lose the role-based guards). PolicyTemplateIntegrityTest in
 * tests/Architecture catches drift on CI.
 */
final class ProductExceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, ProductException $exception): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, ProductException $exception): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, ProductException $exception): bool
    {
        return $user->hasRole('admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
