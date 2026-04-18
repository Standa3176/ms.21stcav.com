<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Compare a single Woo sku-row against its matched supplier row. Decides action +
 * endpoint + payload for the SyncChunkJob to push.
 *
 * SYNC-07: exclude_from_auto_update → 'skipped' regardless of diff.
 * SYNC-06 missing-at-supplier → returns null here (MarkMissingSkusJob handles).
 *
 * Price comparison uses a normalised 2dp-string match (per D-discretion): trailing
 * zeros + trailing dot stripped so '199.00' === '199.0' === '199'.
 */
final class SyncDiffEngine
{
    /**
     * @param  array<string, mixed>  $skuRow       Woo-side row from WooProductIterator.
     * @param  array{price: string, stock: int}|null  $supplierRow  Matched supplier row (null if absent).
     * @return array<string, mixed>|null  {action, endpoint, payload, reason?, old_price?, new_price?, old_stock?, new_stock?}
     */
    public function diff(array $skuRow, ?array $supplierRow): ?array
    {
        if ($skuRow['exclude_from_auto_update'] ?? false) {
            return [
                'action' => 'skipped',
                'endpoint' => $this->endpoint($skuRow),
                'payload' => [],
                'reason' => 'exclude_from_auto_update',
                'old_price' => (string) ($skuRow['price'] ?? ''),
                'new_price' => null,
                'old_stock' => (int) ($skuRow['stock_quantity'] ?? 0),
                'new_stock' => null,
            ];
        }

        if ($supplierRow === null) {
            // Missing-at-supplier — handled by MarkMissingSkusJob (separate post-pass).
            return null;
        }

        $oldPriceNorm = $this->normalisePrice((string) ($skuRow['price'] ?? ''));
        $newPriceNorm = $this->normalisePrice((string) ($supplierRow['price'] ?? ''));
        $oldStock = (int) ($skuRow['stock_quantity'] ?? 0);
        $newStock = (int) ($supplierRow['stock'] ?? 0);

        $priceChanged = $newPriceNorm !== '' && $oldPriceNorm !== $newPriceNorm;
        $stockChanged = $oldStock !== $newStock;

        if (! $priceChanged && ! $stockChanged) {
            return null;  // no-op — supplier matches Woo exactly.
        }

        $payload = [];
        if ($priceChanged) {
            $payload['regular_price'] = (string) $supplierRow['price'];
        }
        if ($stockChanged) {
            $payload['stock_quantity'] = $newStock;
        }

        return [
            'action' => 'updated',
            'endpoint' => $this->endpoint($skuRow),
            'payload' => $payload,
            'old_price' => (string) ($skuRow['price'] ?? ''),
            'new_price' => (string) $supplierRow['price'],
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
        ];
    }

    private function endpoint(array $skuRow): string
    {
        return match ($skuRow['type'] ?? 'simple') {
            'variation' => "products/{$skuRow['woo_product_id']}/variations/{$skuRow['woo_variation_id']}",
            default => "products/{$skuRow['woo_product_id']}",
        };
    }

    /**
     * Normalise a price string to an exact 2dp match form.
     *
     * Examples:
     *   '199.00' → '199'
     *   '199.0'  → '199'
     *   '199'    → '199'
     *   '199.50' → '199.5'
     *   ''       → ''
     */
    private function normalisePrice(string $price): string
    {
        if ($price === '') {
            return '';
        }

        $formatted = number_format((float) $price, 2, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
