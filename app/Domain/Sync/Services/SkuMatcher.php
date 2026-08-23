<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Products\Models\ProductSupplierSku;

/**
 * In-memory supplier-SKU → row hashmap.
 *
 * ~15k SKUs × ~120 bytes = ~1.8MB (A4) — built once per SyncRun at orchestrator start,
 * shared across chunks via the serialised SyncChunkJob payload (Pitfall P2-D).
 * Re-built every run; no cache across runs.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Quick task 260823-clp — alias resolution.
 *
 * Was: `return $this->map[$sku] ?? null;` — exact, one key per row, so a second
 * supplier's code for the same physical part never matched and the part looked
 * new. This is the single choke point every sync path inherits, so the alias
 * fallback lands here.
 *
 * DIRECT-MATCH SEMANTICS ARE UNCHANGED, including case-sensitivity. The
 * 2026-08-09 TODO also proposed folding case here to align with
 * SupplierOfferSnapshot's lowercase-trimmed matchKey, but case-sensitivity is a
 * deliberate AUTO-08 Woo convention guarded by a named test (SkuMatcherTest M2)
 * — and on this path a wrong match means a wrong PRICE, not just a wrong label.
 * Overturning it is a separate operator decision, not a side effect of adding
 * aliases. Left as found.
 *
 * Normalisation therefore applies ONLY to alias resolution: alias codes are
 * stored lowercase-trimmed, so the feed needs a normalised index to look them
 * up whatever casing the supplier used. A case-variant of the product's OWN
 * sku still misses, exactly as before.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class SkuMatcher
{
    /** @var array<string, array{price: string, stock: int}> */
    private array $map = [];

    /**
     * Normalised feed key → row. Used ONLY to resolve alias codes (which are
     * stored lowercase-trimmed); never as a fallback for the product's own sku,
     * which would silently defeat the case-sensitivity guarantee above.
     *
     * @var array<string, array{price: string, stock: int}>
     */
    private array $normalisedMap = [];

    /** @var array<string, array<int, string>> normalised product SKU → its alternative codes */
    private array $aliasesByProductSku = [];

    public function build(array $supplierFeed): self
    {
        $this->map = $supplierFeed;

        $this->normalisedMap = [];
        foreach ($supplierFeed as $key => $row) {
            // First writer wins: a feed carrying both "ABC-1" and "abc-1" keeps
            // the earlier row rather than silently flip-flopping between runs.
            $this->normalisedMap[strtolower(trim((string) $key))] ??= $row;
        }

        return $this;
    }

    /**
     * Load the alternative-code map so match() can fall back to it.
     *
     * Separate from build() and opt-in: build() takes a raw feed array and is
     * called in tests with no database behind it.
     */
    public function withAliases(): self
    {
        $this->aliasesByProductSku = [];

        ProductSupplierSku::query()
            ->with('product:id,sku')
            ->get()
            ->each(function (ProductSupplierSku $alias): void {
                $productSku = $alias->product?->sku;
                if ($productSku === null || $productSku === '') {
                    return;
                }
                $this->aliasesByProductSku[ProductSupplierSku::normalise($productSku)][] = $alias->normalised_sku;
            });

        return $this;
    }

    /**
     * @return array{price: string, stock: int}|null
     */
    public function match(string $sku): ?array
    {
        // 1. Exact — preserves every pre-260823-clp match unchanged, casing
        //    included (SkuMatcherTest M2).
        if (isset($this->map[$sku])) {
            return $this->map[$sku];
        }

        // 2. An alternative supplier code recorded for this product. The alias
        //    INDEX is keyed normalised because that is how aliases are stored;
        //    this does not make the product's own sku lookup case-insensitive.
        $normalised = strtolower(trim($sku));

        foreach ($this->aliasesByProductSku[$normalised] ?? [] as $aliasSku) {
            if (isset($this->normalisedMap[$aliasSku])) {
                return $this->normalisedMap[$aliasSku];
            }
        }

        return null;
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
