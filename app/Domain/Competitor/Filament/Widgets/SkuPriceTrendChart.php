<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Widgets;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use Filament\Widgets\ChartWidget;

/**
 * Phase 5 Plan 04b — Per-SKU price trend chart (COMP-10).
 *
 * Filament 3.3 ChartWidget (Chart.js under the hood). Time-window filter toggles
 * 7/30/90/365 days; each switch rebuilds datasets via Livewire without a page
 * reload. Default window = 30 days (per plan truth-list).
 *
 * Series:
 *   - One line per active Competitor with at least one CompetitorPrice row in
 *     the window for the selected SKU.
 *   - Overlay series for our Phase 3 sell_price (converted to pennies for
 *     consistent y-axis). Stored as decimal(12,4) GBP on products.sell_price,
 *     multiplied by 100 at render time — same convention as
 *     ComputeMarginSuggestionJob (Phase 5 Plan 03).
 *
 * SKU is sourced from the `?sku=` query string on the hosting page; when null,
 * the chart falls back to the most-active CompetitorPrice SKU. Empty data
 * (SKU unknown / no prices in window) returns ['datasets' => [], 'labels' => []]
 * explicitly — Filament silently renders "No data" rather than throwing.
 */
final class SkuPriceTrendChart extends ChartWidget
{
    protected static ?string $heading = 'SKU Price Trend';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    public ?string $sku = null;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last year',
        ];
    }

    public function mount(): void
    {
        // Parent page may pass ?sku=... in the query string.
        $requested = request()->query('sku');
        if (is_string($requested) && $requested !== '') {
            $this->sku = $requested;
        }

        if ($this->sku === null) {
            $this->sku = $this->resolveDefaultSku();
        }
    }

    /**
     * Build Chart.js datasets: one per competitor + one overlay for our sell_price.
     *
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    protected function getData(): array
    {
        // Parse the filter safely — Livewire sends strings for radio-like filters.
        $days = is_numeric($this->filter) ? max(1, (int) $this->filter) : 30;

        if ($this->sku === null || $this->sku === '') {
            return ['datasets' => [], 'labels' => []];
        }

        $from = now()->subDays($days)->startOfDay();
        $labels = [];
        for ($i = $days; $i >= 0; $i--) {
            $labels[] = now()->subDays($i)->format('Y-m-d');
        }

        $competitors = Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $datasets = [];
        $palette = ['#2563eb', '#db2777', '#f97316', '#14b8a6', '#a855f7', '#facc15', '#ef4444'];

        foreach ($competitors as $i => $competitor) {
            $rows = CompetitorPrice::query()
                ->where('competitor_id', $competitor->id)
                ->where('sku', $this->sku)
                ->where('recorded_at', '>=', $from)
                ->orderBy('recorded_at')
                ->get(['price_pennies_ex_vat', 'recorded_at']);

            if ($rows->isEmpty()) {
                continue;
            }

            // Group by date; take last value for each day.
            $byDate = [];
            foreach ($rows as $row) {
                $d = $row->recorded_at?->format('Y-m-d');
                if ($d === null) {
                    continue;
                }
                $byDate[$d] = (int) $row->price_pennies_ex_vat;
            }

            $series = array_map(
                fn (string $label) => $byDate[$label] ?? null,
                $labels,
            );

            $datasets[] = [
                'label' => $competitor->name,
                'data' => $series,
                'borderColor' => $palette[$i % count($palette)],
                'backgroundColor' => 'transparent',
                'spanGaps' => true,
                'tension' => 0.2,
            ];
        }

        // Our sell_price overlay (Phase 3 — decimal(12,4) GBP). Convert to pennies
        // for a matched y-axis. Single horizontal line (value is a single scalar
        // unless the product's sell_price has changed across the window — we
        // show the CURRENT value because Phase 3 recompute is listener-driven).
        $product = Product::where('sku', $this->sku)->first();
        if ($product !== null && $product->sell_price !== null) {
            $ourPennies = (int) round(((float) $product->sell_price) * 100);
            $datasets[] = [
                'label' => sprintf('Our sell price (%s)', $this->sku),
                'data' => array_fill(0, count($labels), $ourPennies),
                'borderColor' => '#10b981',
                'borderDash' => [5, 5],
                'backgroundColor' => 'transparent',
                'pointRadius' => 0,
                'tension' => 0,
            ];
        }

        if ($datasets === []) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    /**
     * Best-effort default SKU pick when none supplied via query string.
     * Picks the SKU with the most recent CompetitorPrice rows — the one the
     * operator most likely wants to see first.
     */
    private function resolveDefaultSku(): ?string
    {
        $row = CompetitorPrice::query()
            ->orderByDesc('recorded_at')
            ->first(['sku']);

        return $row?->sku;
    }
}
