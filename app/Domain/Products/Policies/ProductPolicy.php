<?php

declare(strict_types=1);

namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\Product;
use App\Models\User;

/**
 * Per Phase 1 D-02 role split:
 *   - admin + pricing_manager: edit (create/update)
 *   - sales + read_only:       view-only
 *   - admin only:              delete (hard delete preserved for admin)
 *
 * IMPORTANT: DO NOT regenerate via `php artisan shield:generate --all --panel=admin`.
 * Per Pitfall P2-H this policy is hand-written; regenerating will replace with
 * permission-based stubs and lose the hasRole checks. Plan 02-04 Task 2b
 * documents the restore protocol; Plan 02-05 ships the permanent
 * PolicyTemplateIntegrityTest grep guardrail.
 */
final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->hasRole('admin');
    }
}
