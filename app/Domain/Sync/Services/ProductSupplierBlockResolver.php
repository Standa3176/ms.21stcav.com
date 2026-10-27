<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Models\ProductSupplierBlock;

/**
 * Quick task 261027-duz — per-request cached (sku, supplier_id) offer-block lookup.
 *
 * Mirrors SupplierExclusionResolver: registered as a singleton, lazy-loaded on
 * first read (ONE query per command run), forget() to rebuild. Consumed by
 * SupplierDbSyncCommand::buildBestOfferMap (per-key skip), the
 * products:hydrate-stock-from-offers offer query (whereNotIn) and
 * supplier:explain-cost (BLOCKED marker).
 *
 * Keys are the normalised (lowercase + trim) match key; supplier ids are
 * compared as trimmed strings (feed supplierid / supplier_offer_snapshots.supplier_id).
 */
final class ProductSupplierBlockResolver
{
    /**
     * sku => [supplier_id => reason]. NULL = not loaded yet.
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $cache = null;

    /**
     * @return array<string, string> supplier_id => reason
     */
    public function blocksFor(string $key): array
    {
        return $this->all()[strtolower(trim($key))] ?? [];
    }

    public function isBlocked(string $key, string $supplierId): bool
    {
        $supplierId = trim($supplierId);
        if ($supplierId === '') {
            return false;
        }

        return isset($this->blocksFor($key)[$supplierId]);
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $map = [];
        foreach (ProductSupplierBlock::query()->get(['sku', 'supplier_id', 'reason']) as $row) {
            $map[strtolower(trim((string) $row->sku))][trim((string) $row->supplier_id)] = (string) $row->reason;
        }

        return $this->cache = $map;
    }
}
