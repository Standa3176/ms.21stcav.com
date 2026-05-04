<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Pages;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Quick task 260504-muq + 260504-onx — Price History page.
 *
 * Catalogue → Price History (sort 70). Operator picks a product, sees:
 *   - 4-stat row (current sell / buy / stock + history-day count)
 *   - Chart.js trend chart (sell + buy + stock over up to 90 days)
 *   - Today's per-supplier offers table (cheapest first)
 *   - Cheapest-supplier-per-day table (last 30 days)
 *
 * 260504-onx — replaced the plain HTML <select> (capped at 500 SKUs, no search)
 * with a Filament Select using server-side getSearchResultsUsing across
 * sku / name / short_description so all 5,633 products are reachable + searchable.
 *
 * RBAC: gates on ProductPolicy::viewAny — admin + pricing_manager + sales +
 * read_only all have view (defence-in-depth via panel auth middleware on
 * top of the per-record gate).
 */
class PriceHistoryPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Price History';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = 'Price History';

    protected static string $view = 'filament.pages.price-history';

    public ?int $productId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['productId' => null]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('productId')
                    ->label('Search product')
                    ->placeholder('Type SKU, name, or description…')
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(function (string $search): array {
                        return Product::query()
                            ->where(function ($q) use ($search) {
                                $q->where('sku', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('short_description', 'like', "%{$search}%");
                            })
                            ->orderBy('sku')
                            ->limit(50)
                            ->get(['id', 'sku', 'name'])
                            ->mapWithKeys(fn (Product $p) => [
                                $p->id => trim(($p->sku ?? '—').' — '.Str::limit((string) $p->name, 60)),
                            ])
                            ->all();
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        $p = Product::find($value);
                        if (! $p) {
                            return null;
                        }

                        return ($p->sku ?? '—').' — '.Str::limit((string) $p->name, 60);
                    })
                    ->afterStateUpdated(function ($state) {
                        $this->productId = $state ? (int) $state : null;
                    }),
            ])
            ->statePath('data');
    }

    /**
     * Filament 3 pattern: getViewData() returns variables to the page view.
     * Computed per-render so the chart re-builds when productId changes.
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
