<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Domain\CRM\Models\GdprErasureLogEntry;
use App\Models\User;

/**
 * Phase 4 Plan 05 Task 2 — admin-only read-only policy for GDPR erasure log.
 *
 * Indefinite-retention PII-audit table — admin read-only. All mutations
 * (create/update/delete/restore/forceDelete) are denied because the table
 * is append-only from the GdprEraser service. Even admins cannot edit an
 * erasure record through the UI.
 *
 * Follows the Phase 1 SuggestionPolicy pattern — hardcoded hasRole('admin')
 * survives Shield drift. DO NOT regenerate via `shield:generate`; Plan 02-05
 * PolicyTemplateIntegrityTest catches placeholder stub regressions on every
 * CI run.
 *
 * Restore protocol after accidental shield:generate:
 *   git checkout HEAD -- app/Domain/CRM/Policies/GdprErasureLogEntryPolicy.php
 */
final class GdprErasureLogEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, GdprErasureLogEntry $entry): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        // Append-only from code; never via UI.
        return false;
    }

    public function update(User $user, GdprErasureLogEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, GdprErasureLogEntry $entry): bool
    {
        return false;
    }

    public function restore(User $user, GdprErasureLogEntry $entry): bool
    {
        return false;
    }

    public function forceDelete(User $user, GdprErasureLogEntry $entry): bool
    {
        return false;
    }
}
