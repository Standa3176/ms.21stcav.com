<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Policies;

use App\Domain\Integrations\Models\IntegrationCredential;
use App\Models\User;

/**
 * Phase 09.1 Plan 01 — IntegrationCredentialPolicy (D-12 admin-only).
 *
 * Admin-only — credentials hold encrypted secrets for the 5 integrations
 * (Supplier API JWT, Woo REST, Bitrix webhook, Anthropic API key, Langfuse keys).
 * pricing_manager / sales / read_only all 403 on every method (CONTEXT D-12).
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (would emit Shield placeholder stub literals).
 * tests/Architecture/PolicyTemplateIntegrityTest scans this file on every CI
 * run and goes red on any leak.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Integrations/Policies/IntegrationCredentialPolicy.php
 *
 * Phase 8 ships `shield:safe-regenerate` which automates this restoration —
 * use it instead of bare `shield:generate`.
 */
final class IntegrationCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, IntegrationCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, IntegrationCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, IntegrationCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, IntegrationCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, IntegrationCredential $credential): bool
    {
        return $user->hasRole('admin');
    }
}
