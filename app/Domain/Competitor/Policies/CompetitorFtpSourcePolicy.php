<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorFtpSource;
use App\Models\User;

/**
 * Phase 11.1 Plan 01 — CompetitorFtpSourcePolicy (D-08 + Pitfall P5-F).
 *
 * Admin-only on EVERY method including viewAny — this table holds encrypted
 * FTP/SFTP credentials. pricing_manager / sales / read_only all 403.
 *
 * STRICTER than Phase 5 CompetitorPolicy (which lets pricing_manager view
 * the master register) because credentials sit in this row.
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (would emit Shield placeholder stub literals). The
 * tests/Architecture/PolicyTemplateIntegrityTest scans this file on every
 * CI run and goes red on any leak.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorFtpSourcePolicy.php
 *
 * Phase 8 ships `shield:safe-regenerate` which automates this restoration —
 * use it instead of bare `shield:generate`.
 */
final class CompetitorFtpSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CompetitorFtpSource $source): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CompetitorFtpSource $source): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CompetitorFtpSource $source): bool
    {
        return $user->hasRole('admin');
    }
}
