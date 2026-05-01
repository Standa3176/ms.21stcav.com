<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Policies;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Models\User;

/**
 * Phase 11 Plan 01 — QuoteLinePolicy.
 *
 * Mirrors QuotePolicy role matrix; line-edit gates additionally enforce
 * D-13 line snapshot immutability:
 *   - update/delete: only allowed when parent Quote.status === draft.
 *     After status=sent, line edits are forbidden — UI hides edit buttons
 *     in Plan 11-03; QuoteLineImmutabilityObserver in Plan 11-02 throws
 *     at the model layer if the policy is bypassed.
 *
 * Hand-written hasRole() checks (Pitfall K + P2-H): see QuotePolicy
 * docblock for the Shield restore protocol.
 */
final class QuoteLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, QuoteLine $line): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales']);
    }

    /**
     * D-13 — line edits forbidden after parent quote leaves draft.
     */
    public function update(User $user, QuoteLine $line): bool
    {
        if (! $user->hasAnyRole(['admin', 'pricing_manager', 'sales'])) {
            return false;
        }

        $parentStatus = $line->quote?->status;

        return $parentStatus === Quote::STATUS_DRAFT;
    }

    /**
     * D-13 — line deletion forbidden after parent quote leaves draft.
     */
    public function delete(User $user, QuoteLine $line): bool
    {
        if (! $user->hasAnyRole(['admin', 'pricing_manager'])) {
            return false;
        }

        $parentStatus = $line->quote?->status;

        return $parentStatus === Quote::STATUS_DRAFT;
    }

    public function restore(User $user, QuoteLine $line): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, QuoteLine $line): bool
    {
        return $user->hasRole('admin');
    }
}
