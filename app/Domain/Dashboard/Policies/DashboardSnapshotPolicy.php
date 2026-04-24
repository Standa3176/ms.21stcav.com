<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Policies;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Models\User;

/**
 * Phase 7 Plan 01 — DashboardSnapshotPolicy (D-02 + Shield audit).
 *
 * Role matrix:
 *   - viewAny / view: admin + pricing_manager + sales + read_only
 *     (dashboard widgets are ambient ops intel for every role)
 *   - create / update: DENY for everyone — snapshots are produced only by
 *     the scheduled `dashboard:refresh` command (Plan 07-02), never via
 *     Filament. This denies accidental CRUD wiring.
 *   - delete: admin only (retention prune + operator troubleshooting)
 *
 * Pitfall P5-F — hand-written hasRole checks; DO NOT regenerate via
 * `shield:generate` (PolicyTemplateIntegrityTest catches leaks).
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/Dashboard/Policies/DashboardSnapshotPolicy.php
 */
final class DashboardSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, DashboardSnapshot $snapshot): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Snapshots are produced by dashboard:refresh only — no user CRUD path.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DashboardSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, DashboardSnapshot $snapshot): bool
    {
        return $user->hasRole('admin');
    }
}
