<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Models\User;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpFeedPolicy (D-09 + D-11 + Pitfall P5-F).
 *
 * Admin write + pricing_manager view-only. Sales + read_only have NO access
 * (feeds are operator/credentials-adjacent — too sensitive for pure read tier).
 *
 * RBAC matrix:
 *   - admin           — full CRUD + soft-delete management
 *   - pricing_manager — viewAny + view only (D-11)
 *   - sales           — 403 (Phase 11.2 D-11)
 *   - read_only       — 403 (Phase 11.2 D-11)
 *
 * Pitfall P5-F — hand-written hasRole / hasAnyRole checks; DO NOT regenerate
 * via `shield:generate` (would emit Shield placeholder stub literals). The
 * tests/Architecture/PolicyTemplateIntegrityTest scans this file on every
 * CI run and goes red on any leak. Use `shield:safe-regenerate` instead.
 */
final class CompetitorFtpFeedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, CompetitorFtpFeed $feed): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CompetitorFtpFeed $feed): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CompetitorFtpFeed $feed): bool
    {
        return $user->hasRole('admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, CompetitorFtpFeed $feed): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, CompetitorFtpFeed $feed): bool
    {
        return $user->hasRole('admin');
    }
}
