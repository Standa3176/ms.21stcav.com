<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Models\User;

/**
 * Phase 5 Plan 01 — CompetitorPricePolicy.
 *
 * - viewAny/view: admin + pricing_manager + sales (sales team references
 *   competitor prices when building quotes; COMP-10 trend charts visibility).
 * - create/update/delete: FALSE for all roles. competitor_prices rows are
 *   append-only from IngestCompetitorCsvJob (Plan 05-02) and CSV source
 *   files (COMP-07 mandates "history never truncated" — deletion is out).
 *   Ingest jobs bypass policy checks entirely (jobs don't have a User).
 *
 * Pitfall P5-F — hand-written; DO NOT shield:generate.
 * Restore protocol: git checkout HEAD -- app/Domain/Competitor/Policies/CompetitorPricePolicy.php
 */
final class CompetitorPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales']);
    }

    public function view(User $user, CompetitorPrice $price): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // Prices are write-only from jobs. No UI creation path.
        return false;
    }

    public function update(User $user, CompetitorPrice $price): bool
    {
        // History is immutable per COMP-07.
        return false;
    }

    public function delete(User $user, CompetitorPrice $price): bool
    {
        // History is immutable per COMP-07. Retention prunes raw CSV files,
        // never this table's rows.
        return false;
    }
}
