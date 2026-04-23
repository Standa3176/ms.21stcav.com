<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Policies;

use App\Models\User;

/**
 * Phase 6 Plan 04 — AutoCreateSettingsPolicy.
 *
 * Gates the AutoCreateSettingsPage singleton (admin-only). There is NO
 * underlying Eloquent model — Settings are stored via config + an optional
 * settings row; this policy class is registered as a pseudo-policy against
 * a string sentinel (\App\Domain\ProductAutoCreate\Filament\Pages\AutoCreateSettingsPage)
 * so consumers can call `auth()->user()->can('update', AutoCreateSettings::class)`.
 *
 * Role matrix:
 *   - viewAny/view/update: admin ONLY (draft-vs-immediate-publish is a
 *     load-bearing governance decision — AUTO-07)
 *   - create/delete: false for everyone (singleton)
 *   - sales + read_only + pricing_manager: denied
 *
 * Pitfall P5-F — hand-written hasRole; DO NOT regenerate via shield:generate
 * (PolicyTemplateIntegrityTest catches placeholder leaks).
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/ProductAutoCreate/Policies/AutoCreateSettingsPolicy.php
 */
final class AutoCreateSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
