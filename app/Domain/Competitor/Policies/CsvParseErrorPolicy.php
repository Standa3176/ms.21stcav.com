<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Policies;

use App\Domain\Competitor\Models\CsvParseError;
use App\Models\User;

/**
 * Phase 5 Plan 01 — CsvParseErrorPolicy (COMP-05 triage surface).
 *
 * - viewAny/view: admin + pricing_manager.
 * - update: admin + pricing_manager (marking an error resolved after
 *   fixing the source CSV is a pricing-manager workflow).
 * - create: FALSE (rows are written by ingest jobs, not humans).
 * - delete: admin only (archival cleanup, not an operational action).
 *
 * Pitfall P5-F — hand-written; DO NOT shield:generate.
 * Restore protocol: git checkout HEAD -- app/Domain/Competitor/Policies/CsvParseErrorPolicy.php
 */
final class CsvParseErrorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function view(User $user, CsvParseError $error): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CsvParseError $error): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function delete(User $user, CsvParseError $error): bool
    {
        return $user->hasRole('admin');
    }
}
