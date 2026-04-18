<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * In-memory supplier-SKU → row hashmap.
 *
 * ~15k SKUs × ~120 bytes = ~1.8MB (A4) — built once per SyncRun at orchestrator start,
 * shared across chunks via the serialised SyncChunkJob payload (Pitfall P2-D).
 * Re-built every run; no cache across runs.
 *
 * Case-sensitive matching per AUTO-08 Woo convention — ops can revisit in Phase 6.
 */
final class SkuMatcher
{
    /** @var array<string, array{price: string, stock: int}> */
    private array $map = [];

    public function build(array $supplierFeed): self
    {
        $this->map = $supplierFeed;

        return $this;
    }

    /**
     * @return array{price: string, stock: int}|null
     */
    public function match(string $sku): ?array
    {
        return $this->map[$sku] ?? null;
    }

    /** @return array<int, string> */
    public function supplierSkus(): array
    {
        return array_keys($this->map);
    }

    public function count(): int
    {
        return count($this->map);
    }
}
