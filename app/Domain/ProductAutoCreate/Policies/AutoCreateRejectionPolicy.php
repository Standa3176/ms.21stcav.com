<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Policies;

use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Models\User;

/**
 * Phase 6 Plan 01 — AutoCreateRejectionPolicy (D-06).
 *
 * Rejections are append-only audit records — same pattern as Phase 4 D-13
 * GdprErasureLogEntryPolicy (create-via-Filament-Action only, no edit path).
 *
 * Role matrix:
 *   - viewAny/view: admin + pricing_manager (rejection intelligence feeds
 *     future auto-skip-rule suggestions per D-06)
 *   - create: admin + pricing_manager (Filament reject-with-reason action
 *     on the review inbox — Plan 06-04)
 *   - update/delete: FALSE for everyone — rejections are retention-indefinite
 *     per Phase 1 D-04. An edit would obscure the audit trail.
 *
 * Pitfall P5-F — hand-written; DO NOT shield:generate.
 * Restore protocol: git checkout HEAD -- app/Domain/ProductAutoCreate/Policies/AutoCreateRejectionPolicy.php
 */
final class AutoCreateRejectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, AutoCreateRejection $rejection): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, AutoCreateRejection $rejection): bool
    {
        return false;
    }

    public function delete(User $user, AutoCreateRejection $rejection): bool
    {
        return false;
    }
}
