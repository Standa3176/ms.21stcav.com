<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Models\User;

/**
 * Phase 5 Plan 01 — CompetitorCsvMappingPolicy (D-04).
 *
 * - viewAny/view: admin + pricing_manager.
 * - update: admin + pricing_manager (D-04 — pricing_manager resolves
 *   quarantined ambiguous-mapping CSVs via the Filament Ingest Issues
 *   page; this is the ONLY manual config surface in the pipeline).
 * - create: FALSE — mappings are auto-created by ColumnHeuristicDetector
 *   on first successful ingest.
 * - delete: admin only (resetting a mapping is infrequent and disruptive).
 *
 * Pitfall P5-F — hand-written; DO NOT shield:generate.
 * Restore protocol: git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorCsvMappingPolicy.php
 */
final class CompetitorCsvMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, CompetitorCsvMapping $mapping): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // Mappings are auto-created by the ingest pipeline, not by humans.
        return false;
    }

    public function update(User $user, CompetitorCsvMapping $mapping): bool
    {
        // D-04: pricing_manager can resolve quarantined mappings.
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, CompetitorCsvMapping $mapping): bool
    {
        return $user->hasRole('admin');
    }
}
