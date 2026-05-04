<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Pages;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Filament\Pages\Page;

/**
 * Quick task 260504-muq — Price History page.
 *
 * Catalogue → Price History (sort 70). Operator picks a product, sees:
 *   - 4-stat row (current sell / buy / stock + history-day count)
 *   - Chart.js trend chart (sell + buy + stock over up to 90 days)
 *   - Today's per-supplier offers table (cheapest first)
 *   - Cheapest-supplier-per-day table (last 30 days)
 *
 * RBAC: gates on ProductPolicy::viewAny — admin + pricing_manager + sales +
 * read_only all have view (defence-in-depth via panel auth middleware on
 * top of the per-record gate).
 */
class PriceHistoryPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Price History';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'Price History';

    protected static string $view = 'filament.pages.price-history';

    public ?int $productId = null;

    public function mount(): void
    {
        // No-op default — operator selects a product via the dropdown.
    }

    /**
     * Filament 3 pattern: getViewData() returns variables to the page view.
     * Computed per-render so the chart re-builds when productId changes
     * (Livewire $set fires a re-render; Blade re-evaluates getViewData()).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->productId === null) {
            return ['product' => null];
        }

        $product = Product::find($this->productId);
        if (! $product) {
            return ['product' => null];
        }

        $snapshots = ProductPriceSnapshot::where('product_id', $product->id)
            ->orderByDesc('recorded_at')
            ->limit(90)
            ->get();

        $offerSnapshotsToday = SupplierOfferSnapshot::where('product_id', $product->id)
            ->whereDate('recorded_at', today())
            ->orderBy('price')
            ->get();

        // Cheapest supplier per day for last 30 days. Group rows by date,
        // then collection->first() pulls the row with the lowest price (the
        // query is already sorted price ASC so first() is the min).
        $cheapestPerDay = SupplierOfferSnapshot::where('product_id', $product->id)
            ->where('recorded_at', '>=', today()->subDays(30))
            ->whereNotNull('price')
            ->orderBy('recorded_at', 'desc')
            ->orderBy('price', 'asc')
            ->get()
            ->groupBy(fn ($r) => $r->recorded_at->format('Y-m-d'))
            ->map(fn ($group) => $group->first())
            ->values();

        return [
            'product' => $product,
            'snapshots' => $snapshots,
            'offerSnapshotsToday' => $offerSnapshotsToday,
            'cheapestPerDay' => $cheapestPerDay,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Product::class) ?? false;
    }
}
