<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Policies;

use App\Domain\Dashboard\Models\UserSavedFilter;
use App\Models\User;

/**
 * Phase 7 Plan 01 — UserSavedFilterPolicy (D-07 + Threat T-07-01-01).
 *
 * Role matrix:
 *   - viewAny:           any authenticated user (they'll only see their own;
 *                        Plan 07-03 scopes getEloquentQuery to auth()->id())
 *   - view / update / delete: OWNER ONLY (filter->user_id === user->id)
 *   - delete:            owner OR admin (admins can clear stale filters for
 *                        departed users)
 *   - create:            any authenticated user
 *
 * T-07-01-01 (cross-user read): defence-in-depth layer on top of Plan 07-03's
 * query scope. If a rogue controller forgets to scope by user_id, this
 * policy still blocks read/update/delete on other users' rows.
 *
 * Pitfall P5-F — hand-written hasRole/ownership checks; DO NOT regenerate
 * via `shield:generate` (PolicyTemplateIntegrityTest catches leaks).
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Dashboard/Policies/UserSavedFilterPolicy.php
 */
final class UserSavedFilterPolicy
{
    public function viewAny(User $user): bool
    {
        // Every authenticated user sees their own saved filters. The actual
        // row-level filter lives in Plan 07-03's Resource getEloquentQuery.
        return true;
    }

    public function view(User $user, UserSavedFilter $filter): bool
    {
        return $filter->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserSavedFilter $filter): bool
    {
        return $filter->user_id === $user->id;
    }

    public function delete(User $user, UserSavedFilter $filter): bool
    {
        return $filter->user_id === $user->id || $user->hasRole('admin');
    }
}
