<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Models\User;

/**
 * Phase 5 Plan 01 — CompetitorIngestRunPolicy.
 *
 * - viewAny/view: admin + pricing_manager + sales (ingest run visibility
 *   is operational intel — all three roles need to know "was a price
 *   just imported?" for quote / margin / stock conversations).
 * - create/update/delete: FALSE. Runs are append-only from the watcher
 *   command (Plan 05-02) and ingest jobs; no UI edit path.
 *
 * Pitfall P5-F — hand-written; DO NOT shield:generate.
 * Restore protocol: git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorIngestRunPolicy.php
 */
final class CompetitorIngestRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales']);
    }

    public function view(User $user, CompetitorIngestRun $run): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CompetitorIngestRun $run): bool
    {
        return false;
    }

    public function delete(User $user, CompetitorIngestRun $run): bool
    {
        return false;
    }
}
