<?php

declare(strict_types=1);

namespace App\Domain\Products\Observers;

use App\Domain\Products\Models\ProductVariant;

/**
 * Pitfall P2-C mitigation — bump parent Product's last_synced_at whenever a
 * child variation is saved so the SyncRunResource drill-down doesn't show
 * "parent last synced 3 days ago" when a variation was touched 5 minutes ago.
 *
 * DO NOT REMOVE — purpose documented in .planning/phases/02-supplier-sync/
 * 02-RESEARCH.md §Pitfall P2-C and 02-01-PLAN.md task 2 done criteria.
 */
final class ProductVariantObserver
{
    public function saved(ProductVariant $variant): void
    {
        // `touch()` bumps ONLY the timestamp column without triggering the
        // parent's LogsActivity trait on every variation save (prevents
        // activity_log bloat). Uses forceFill + saveQuietly under the hood.
        $variant->product?->forceFill(['last_synced_at' => now()])->saveQuietly();
    }
}
