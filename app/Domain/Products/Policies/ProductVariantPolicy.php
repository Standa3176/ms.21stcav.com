<?php

declare(strict_types=1);

namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\ProductVariant;
use App\Models\User;

/**
 * Identical gates to ProductPolicy — pricing_manager + admin may edit variations,
 * sales + read_only may only view. Pitfall P2-H: DO NOT regenerate via
 * shield:generate (see ProductPolicy docblock for the restore protocol).
 */
final class ProductVariantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $user->hasRole('admin');
    }
}
