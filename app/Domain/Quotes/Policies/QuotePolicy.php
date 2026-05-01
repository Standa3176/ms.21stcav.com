<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Policies;

use App\Domain\Quotes\Models\Quote;
use App\Models\User;

/**
 * Phase 11 Plan 01 — QuotePolicy (QUOT-03 + D-04 + D-05 + D-07).
 *
 * Role matrix:
 *   - admin + pricing_manager: viewAny/view/create/update/delete/restore/forceDelete
 *   - sales: viewAny/view/create/update + markAccepted/markRejected
 *   - read_only: viewAny/view only
 *
 * Plan 11 specific actions:
 *   - approve(): admin + pricing_manager only — D-04 separation-of-duties.
 *     Sales role explicitly DENIED so a salesperson cannot approve their
 *     own quote (T-11-01-03 mitigation). Sees the button as disabled with
 *     tooltip "ask pricing_manager or admin to approve" in Plan 11-03.
 *   - revert(): admin only AND quote.status=sent AND within 5 minutes of
 *     send (D-05 — narrow window before customer reads the PDF). After
 *     5 minutes the action is hidden in the UI.
 *   - markAccepted/markRejected(): admin + pricing_manager + sales — D-07
 *     manual sales acceptance bookkeeping (no public accept-link in v1).
 *
 * Hand-written hasRole() checks (Pitfall K + P2-H): DO NOT regenerate this
 * policy via `php artisan shield:generate --all --panel=admin`. Shield 3.9.10
 * replaces hand-written policies with permission-based stubs and leaks
 * placeholder literal strings; PolicyTemplateIntegrityTest catches that
 * regression on every CI run.
 *
 * Restore protocol after accidental shield:generate run:
 *   git checkout HEAD -- app/Domain/Quotes/Policies/QuotePolicy.php
 */
final class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales']);
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales']);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager']);
    }

    public function restore(User $user, Quote $quote): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Quote $quote): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * D-04 separation-of-duties — sales role explicitly DENIED.
     *
     * A salesperson MUST NOT approve their own quote. Only admin or
     * pricing_manager can flip status from draft → sent. The approve action
     * is the load-bearing single-button transition that fires QuoteApproved
     * → PushQuoteToBitrix listener → Bitrix Deal creation (Plan 11-04).
     */
    public function approve(User $user, Quote $quote): bool
    {
        // Sales explicitly DENIED — separation-of-duties (T-11-01-03 mitigation).
        if ($user->hasRole('sales') && ! $user->hasAnyRole(['admin', 'pricing_manager'])) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'pricing_manager'])
            && $quote->status === Quote::STATUS_DRAFT;
    }

    /**
     * D-05 admin-only revert window — narrow 5-minute escape hatch for
     * accidental sends before the customer reads the PDF. After 5 minutes
     * the action is hidden in the Filament Resource (Plan 11-03).
     */
    public function revert(User $user, Quote $quote): bool
    {
        if (! $user->hasRole('admin')) {
            return false;
        }
        if ($quote->status !== Quote::STATUS_SENT) {
            return false;
        }
        if ($quote->sent_at === null) {
            return false;
        }

        return $quote->sent_at->diffInMinutes(now()) < 5;
    }

    /**
     * D-07 — manual sales acceptance. Sales clicks "Mark as accepted" in
     * Plan 11-03 row action; writes audit_log + fires QuoteAccepted event.
     */
    public function markAccepted(User $user, Quote $quote): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales'])
            && $quote->status === Quote::STATUS_SENT;
    }

    /**
     * D-07 + D-08 — manual sales rejection. Captures structured reason
     * (RejectionReason enum) + optional free-text note via Filament action
     * form in Plan 11-03.
     */
    public function markRejected(User $user, Quote $quote): bool
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales'])
            && $quote->status === Quote::STATUS_SENT;
    }
}
