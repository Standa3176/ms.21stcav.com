<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Models\User;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpCredentialPolicy (D-09 + Pitfall P5-F).
 *
 * Admin-only — credentials hold encrypted FTP secrets; ops users do not need
 * access. STRICTER than the rest of the Phase 5 Competitor policies because
 * password / private_key / passphrase ciphertext lives in this row.
 *
 * pricing_manager / sales / read_only all 403 on every method.
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (would emit Shield placeholder stub literals). The
 * tests/Architecture/PolicyTemplateIntegrityTest scans this file on every
 * CI run and goes red on any leak.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorFtpCredentialPolicy.php
 *
 * Phase 8 ships `shield:safe-regenerate` which automates this restoration —
 * use it instead of bare `shield:generate`.
 */
final class CompetitorFtpCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CompetitorFtpCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CompetitorFtpCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CompetitorFtpCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, CompetitorFtpCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, CompetitorFtpCredential $credential): bool
    {
        return $user->hasRole('admin');
    }
}
