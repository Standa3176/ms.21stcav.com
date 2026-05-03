<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Observers;

use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 09.1 Plan 01 — IntegrationCredentialObserver (D-06 cache invalidation).
 *
 * Forgets the resolver's per-kind cache key on every save/delete/forceDelete
 * so operator credential rotation takes effect within ≤60s. Boot binding in
 * AppServiceProvider.
 */
class IntegrationCredentialObserver
{
    public function saved(IntegrationCredential $credential): void
    {
        $this->invalidate($credential);
    }

    public function deleted(IntegrationCredential $credential): void
    {
        $this->invalidate($credential);
    }

    public function forceDeleted(IntegrationCredential $credential): void
    {
        $this->invalidate($credential);
    }

    private function invalidate(IntegrationCredential $credential): void
    {
        $kind = $credential->kind; // already cast to IntegrationCredentialKind enum
        if ($kind !== null) {
            Cache::forget(IntegrationCredentialResolver::cacheKeyFor($kind));
        }
    }
}
