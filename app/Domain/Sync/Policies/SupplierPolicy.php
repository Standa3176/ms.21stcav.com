<?php

declare(strict_types=1);

namespace App\Domain\Sync\Policies;

use App\Domain\Sync\Models\Supplier;
use App\Models\User;

/**
 * 260710-pdw — RBAC/Shield consistency policy for the Supplier metadata model.
 *
 * Mirrors SyncRunPolicy (same Sync domain, same producer-owned pattern):
 * Supplier rows are AUTO-DISCOVERED by `suppliers:check-stale` — there is no UI
 * creation and no ad-hoc row edits, so create/update are disabled. view/viewAny
 * open to all 4 roles (shared-workspace read of non-secret supplier metadata,
 * matching SyncRunPolicy). delete is admin-only. `sync` mirrors the existing
 * inline SupplierResource write-gating (admin + pricing_manager) so Shield can
 * manage it without changing behaviour.
 *
 * ADDITIVE ONLY: the SupplierResource inline hasAnyRole(['admin','pricing_manager'])
 * gating on ->disabled columns + the form stays the source of truth for the toggle
 * writes; this policy does not remove or override it.
 *
 * Pitfall P2-H: DO NOT regenerate via `shield:generate`. Hand-written; must keep
 * hasRole/hasAnyRole references + zero Shield curly-brace placeholder leaks
 * (PolicyTemplateIntegrityTest).
 */
final class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        // Shared-workspace read: supplier metadata is non-secret operational intel.
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // Suppliers are auto-discovered by suppliers:check-stale — no UI creation.
        return false;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        // Supplier rows are sync-owned; no ad-hoc UI edits.
        return false;
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasRole('admin');
    }

    public function sync(User $user): bool
    {
        // Matches SupplierResource inline write-gating (Active toggle + form).
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }
}
