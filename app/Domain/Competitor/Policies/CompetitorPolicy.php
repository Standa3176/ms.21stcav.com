<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\Competitor;
use App\Models\User;

/**
 * Phase 5 Plan 01 — CompetitorPolicy (D-02 + D-04 role split).
 *
 * - viewAny/view: admin + pricing_manager (both need to see tracked competitors).
 * - create/update/delete: admin only (ops governance over the competitor register).
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (would emit Shield placeholder stub literals). The
 * tests/Architecture/PolicyTemplateIntegrityTest scans this file on every
 * CI run and will go red on any leak.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorPolicy.php
 */
final class CompetitorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, Competitor $competitor): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Competitor $competitor): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Competitor $competitor): bool
    {
        return $user->hasRole('admin');
    }
}
