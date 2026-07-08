<?php

declare(strict_types=1);

namespace App\Foundation\Audit\Services;

use Illuminate\Support\Facades\Context;

/**
 * Meta-audit helper. Wraps spatie/activitylog for system-level events that
 * don't attach to a specific Eloquent model — prune counts, role-sync outcomes,
 * scheduled-command triggers, shadow-mode diff outcomes, etc.
 *
 * Model-level changes (PricingRule updated, Product created) use the LogsActivity
 * trait on the model directly — NOT this service.
 *
 * D-09 compliance: retention prune commands call Auditor::record() so the prune
 * action itself is auditable. FOUND-04 compliance: correlation_id threads through.
 */
class Auditor
{
    public function record(string $action, array $context = []): void
    {
        activity('system')
            ->withProperties(array_merge([
                'correlation_id' => Context::get('correlation_id'),
                'occurred_at' => now()->toIso8601String(),
            ], $context))
            ->log($action);
    }
}
