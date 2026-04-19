<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Models\PricingRule;
use App\Models\User;

/**
 * Phase 3 Plan 01 — PricingRulePolicy (T-03-01-04 mitigation + Phase 1 D-02).
 *
 * Role matrix:
 *   - admin + pricing_manager: viewAny/view/create/update/delete/restore/forceDelete
 *   - sales + read_only:       viewAny/view only
 *
 * Hand-written hasRole() checks (Pitfall K + P2-H): DO NOT regenerate this
 * policy via `php artisan shield:generate --all --panel=admin`. Shield 3.9.10
 * replaces hand-written policies with permission-based stubs and leaks
 * placeholder literal strings; Plan 02-05's Architecture-suite
 * PolicyTemplateIntegrityTest catches that regression on every CI run.
 *
 * Restore protocol after accidental shield:generate run:
 *   git checkout HEAD -- app/Domain/Pricing/Policies/PricingRulePolicy.php
 */
final class PricingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, PricingRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function update(User $user, PricingRule $rule): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, PricingRule $rule): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function restore(User $user, PricingRule $rule): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, PricingRule $rule): bool
    {
        return $user->hasRole('admin');
    }
}
