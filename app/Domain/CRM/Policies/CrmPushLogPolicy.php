<?php

declare(strict_types=1);

namespace App\Domain\CRM\Policies;

use App\Foundation\Integration\Models\IntegrationEvent;
use App\Models\User;

/**
 * Phase 4 Plan 04 — read-only policy for the CRM Push Log (CRM-11).
 *
 * CrmPushLogResource is a filtered view over integration_events WHERE
 * channel='bitrix'. Admin + sales can view; ALL mutations disabled (the
 * underlying table is append-only via IntegrationLogger).
 *
 * Hand-written hasRole() per Pitfall K + P2-H — do NOT regenerate via
 * shield:generate. PolicyTemplateIntegrityTest catches double-curly
 * placeholder leaks on every CI run.
 *
 * Note: the Resource binds to IntegrationEvent — this policy is registered
 * against that model via Gate::policy() in AppServiceProvider::boot.
 */
final class CrmPushLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('sales');
    }

    public function view(User $user, IntegrationEvent $event): bool
    {
        return $user->hasRole('admin') || $user->hasRole('sales');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, IntegrationEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, IntegrationEvent $event): bool
    {
        return false;
    }

    public function restore(User $user, IntegrationEvent $event): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntegrationEvent $event): bool
    {
        return false;
    }

    /** Admin-only action — only admin may trigger a replay on a failed row. */
    public function replay(User $user, IntegrationEvent $event): bool
    {
        return $user->hasRole('admin');
    }
}
