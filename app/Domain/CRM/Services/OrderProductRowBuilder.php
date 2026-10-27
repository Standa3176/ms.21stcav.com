<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

/**
 * Phase 12.1 Plan 03 (D-03/D-04/D-16) — Woo order -> Bitrix product rows.
 *
 * Pure: no DB, no config, no facades. Money is handled in integer pence.
 * Row shape mirrors PushQuoteToBitrixDealJob::buildLineRows. TAX_RATE is
 * derived per line from the charged Woo tax (D-16), never fixed at 20; PRICE
 * always carries the actual charged amount so sum(rows) reconciles with the
 * order total whatever the rate.
 */
final class OrderProductRowBuilder
{
    /** @var list<int> */
    private const STANDARD_RATES = [0, 5, 20];

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    public function build(array $order): array
    {
        $rows = [];
        $sort = 0;

        foreach ((array) ($order['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $net = $this->pence($line['total'] ?? 0);
            $tax = $this->pence($line['total_tax'] ?? 0);
            $unitEx = (int) round($net / $qty);
            $unitInc = (int) round(($net + $tax) / $qty);
            $name = (string) ($line['name'] ?? '');
            if ($name === '') {
                $name = (string) ($line['sku'] ?? 'Item');
            }
            $sort += 10;
            $rows[] = $this->row($name, $unitInc, $unitEx, (string) $qty, $this->taxRate($net, $tax), $sort);
        }

        $shipNet = $this->pence($order['shipping_total'] ?? 0);
        if ($shipNet > 0) {
            $shipTax = $this->pence($order['shipping_tax'] ?? 0);
            $method = trim((string) (((array) ($order['shipping_lines'] ?? []))[0]['method_title'] ?? ''));
            $sort += 10;
            $rows[] = $this->row(
                $method !== '' ? 'Shipping: '.$method : 'Shipping',
                $shipNet + $shipTax,
                $shipNet,
                '1',
                $this->taxRate($shipNet, $shipTax),
                $sort,
            );
        }

        return $rows;
    }

    /**
     * Stable hash of what the product rows depend on (D-04): line items +
     * shipping. Unaffected by status, notes or meta.
     *
     * @param  array<string, mixed>  $order
     */
    public function lineItemsHash(array $order): string
    {
        $items = [];
        foreach ((array) ($order['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $items[] = [
                (int) ($line['product_id'] ?? 0),
                (int) ($line['variation_id'] ?? 0),
                (string) ($line['sku'] ?? ''),
                (int) ($line['quantity'] ?? 0),
                (string) ($line['total'] ?? ''),
                (string) ($line['total_tax'] ?? ''),
            ];
        }
        usort($items, fn (array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3], $a[4]] <=> [$b[0], $b[1], $b[2], $b[3], $b[4]]);

        return hash('sha256', (string) json_encode([
            $items,
            [(string) ($order['shipping_total'] ?? ''), (string) ($order['shipping_tax'] ?? '')],
        ]));
    }

    /** @return array<string, mixed> */
    private function row(string $name, int $unitInc, int $unitEx, string $qty, string $taxRate, int $sort): array
    {
        $inc = number_format($unitInc / 100, 2, '.', '');
        $ex = number_format($unitEx / 100, 2, '.', '');

        return [
            'PRODUCT_ID' => 0,
            'PRODUCT_NAME' => $name,
            'PRICE' => $inc,
            'PRICE_EXCLUSIVE' => $ex,
            'PRICE_NETTO' => $ex,
            'PRICE_BRUTTO' => $inc,
            'QUANTITY' => $qty,
            'TAX_RATE' => $taxRate,
            'TAX_INCLUDED' => 'Y',
            'CUSTOMIZED' => 'Y',
            'MEASURE_CODE' => 796,
            'MEASURE_NAME' => 'pcs',
            'SORT' => $sort,
        ];
    }

    private function taxRate(int $netPence, int $taxPence): string
    {
        if ($taxPence <= 0 || $netPence <= 0) {
            return '0';
        }
        $pct = $taxPence / $netPence * 100;
        foreach (self::STANDARD_RATES as $rate) {
            if (abs($pct - $rate) <= 0.5) {
                return (string) $rate;
            }
        }

        return (string) (int) round($pct);
    }

    private function pence(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
