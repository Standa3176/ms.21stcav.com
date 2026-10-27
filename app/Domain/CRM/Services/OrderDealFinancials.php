<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Support\BitrixFieldGuards;

/**
 * Phase 12.1 Plan 03 (D-05/D-06) — pure Cost / Predicted Margin maths in pence.
 *
 * Cost = sum(buy_price ex VAT x qty); Predicted Margin = product net sales
 * (line totals ex tax, after discount) - Cost. Shipping is excluded from both.
 * If any product line cannot be costed both values are null (never partial).
 */
final class OrderDealFinancials
{
    /**
     * @param  array<int, mixed>  $lineItems  Woo line_items
     * @param  array<string, ?string>  $buyPriceBySku  sku => buy_price ex VAT (null / <= 0 = unresolvable)
     */
    public function compute(array $lineItems, array $buyPriceBySku): OrderFinancialsResult
    {
        $netSales = 0;
        $cost = 0;
        $missing = [];

        foreach ($lineItems as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $netSales += (int) round((float) ($line['total'] ?? 0) * 100);

            $sku = trim((string) ($line['sku'] ?? ''));
            if ($sku === '') {
                $missing[] = '(no sku) '.(string) ($line['name'] ?? '');

                continue;
            }
            $buy = $buyPriceBySku[$sku] ?? null;
            if ($buy === null || (float) $buy <= 0) {
                $missing[] = $sku;

                continue;
            }
            $cost += (int) round(round((float) $buy * 10000) * $qty / 100);
        }

        $missing = array_values(array_unique($missing));
        if ($missing !== []) {
            return new OrderFinancialsResult(false, null, $netSales, null, $missing);
        }

        return new OrderFinancialsResult(true, $cost, $netSales, $netSales - $cost, []);
    }

    /**
     * @param  array<string, mixed>  $schemaFields  BitrixSchemaCache::fieldsFor('deal')
     * @return array<string, string>
     */
    public function fieldsFor(
        OrderFinancialsResult $r,
        ?string $costCode,
        ?string $marginCode,
        array $schemaFields,
        string $currency,
        bool $forUpdate,
    ): array {
        $costCode = trim((string) $costCode);
        $marginCode = trim((string) $marginCode);
        if ($costCode === '' || $marginCode === ''
            || BitrixFieldGuards::isForbiddenTarget($costCode)
            || BitrixFieldGuards::isForbiddenTarget($marginCode)) {
            return [];
        }

        if (! $r->complete) {
            return $forUpdate ? [$costCode => '', $marginCode => ''] : [];
        }

        return [
            $costCode => $this->format((int) $r->costPence, $schemaFields[$costCode] ?? [], $currency),
            $marginCode => $this->format((int) $r->predictedMarginPence, $schemaFields[$marginCode] ?? [], $currency),
        ];
    }

    /** @param  array<string, mixed>|mixed  $descriptor */
    private function format(int $pence, mixed $descriptor, string $currency): string
    {
        $amount = number_format($pence / 100, 2, '.', '');
        $type = is_array($descriptor) ? (string) ($descriptor['type'] ?? '') : '';

        return $type === 'money' ? $amount.'|'.strtoupper($currency !== '' ? $currency : 'GBP') : $amount;
    }
}
