<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Quick task 260626-oqr — operator-excluded supplier resolver.
 *
 * The `suppliers.is_active` boolean has existed since 260608-g8x (its migration
 * docblock literally cites "Nuvias … uploads erratically" as the motivating
 * case) but was DEAD: nothing read it. This resolver wires it up.
 *
 * An operator marks a supplier inactive (suppliers.is_active=false) via the
 * Filament Suppliers page; SupplierDbSyncCommand::buildBestOfferMap then DROPS
 * that supplier's offers entirely — price AND stock — exactly as a stale
 * supplier is dropped, but UNCONDITIONALLY (an explicit operator exclusion
 * outranks the freshness policy and is not behind any flag).
 *
 * Mirrors SupplierFreshnessResolver: registered as a singleton so the
 * per-request cache is shared (at most one query per command run), and
 * supplier_ids are cast to string (suppliers.supplier_id is VARCHAR(16)).
 */
final class SupplierExclusionResolver
{
    /**
     * Per-request cache. NULL = not loaded yet (lazy on first read).
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $cache = null;

    /**
     * Operator-excluded supplier_ids (is_active=false). Strings.
     *
     * @return Collection<int, string>
     */
    public function excludedSupplierIds(): Collection
    {
        return $this->cache ??= Supplier::query()
            ->where('is_active', false)
            ->pluck('supplier_id')
            ->map(static fn ($id): string => (string) $id)
            ->values();
    }

    public function isExcluded(string $supplierId): bool
    {
        return $this->excludedSupplierIds()->contains($supplierId);
    }

    /**
     * Force-rebuild the per-request cache. Tests + the sync command call this
     * between data mutations and reads (mirrors SupplierFreshnessResolver).
     */
    public function forget(): void
    {
        $this->cache = null;
    }
}
