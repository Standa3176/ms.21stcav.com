<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Quick task 260719-mgp — thin injectable seam over the REMOTE supplier feed.
 *
 * The sourceability-gap probe (supplier:probe-sourceability-gap) needs the feed
 * rows for a given manufacturer so it can classify why an on-Woo product is not
 * in supplier_sku_cache. Reading the remote per-supplier feed (feeds_products on
 * the supplier_db VPS) is the one live-network step; isolating it behind this
 * interface lets the classification logic be unit-tested against an in-memory
 * fake with NO VPS connection (mirrors the SourcingGapScanner ↔
 * SupplierFeedSourceabilityChecker DI seam).
 *
 * READ-ONLY. Implementations must never write to the feed, never write to Woo,
 * and must bound every remote query (LIMIT + product_excluded=0).
 */
interface SupplierFeedReader
{
    /**
     * Return the feed rows whose manufacturer matches $manufacturer, capped at
     * $cap rows. Match is prefix-based + case/space-insensitive so a clean brand
     * ("Yealink") also picks up "Brand - Category"-shaped feed manufacturers
     * ("Yealink - Headset").
     *
     * @return array<int, array{mpn: string, suppliersku: string}>
     */
    public function rowsForManufacturer(string $manufacturer, int $cap = 5000): array;
}
