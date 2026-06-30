<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Concerns;

trait PrefersRealSupplierRow
{
    /**
     * From multiple supplier rows for one SKU, pick the best:
     *   1. manufacturer resolves to a Woo brand (real product, not a warranty/add-on label)
     *   2. in stock (stock > 0)
     *   3. input order (caller passes ORDER BY updated_at DESC → most-recent is the final tiebreak)
     * Returns the chosen row, or null when $rows is empty.
     *
     * @param  array<int, array<string, mixed>>  $rows  each row has at least 'manufacturer' and 'stock'
     * @param  callable(string): bool  $isBrand  manufacturer → does it resolve to a Woo brand?
     * @return array<string, mixed>|null
     */
    protected function pickBestSupplierRow(array $rows, callable $isBrand): ?array
    {
        if ($rows === []) {
            return null;
        }
        $bestIdx = 0;
        $bestScore = [-1, -1];
        foreach (array_values($rows) as $i => $r) {
            $score = [
                $isBrand(trim((string) ($r['manufacturer'] ?? ''))) ? 1 : 0,
                ((int) ($r['stock'] ?? 0)) > 0 ? 1 : 0,
            ];
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        return array_values($rows)[$bestIdx];
    }
}
