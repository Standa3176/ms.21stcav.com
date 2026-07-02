<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Pages;

use App\Console\Commands\RefreshBrandsToAddCommand;
use App\Domain\Sync\Services\WooClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Quick task 260702-hg1 — Brands to Add page (Piece 2 of the workflow).
 *
 * /admin/brands-to-add — operator-facing UI listing the brands that appear on
 * pending new_product_opportunity suggestions but are NOT yet on Woo. Piece 1
 * (260702-h50, RefreshBrandsToAddCommand) tags each suggestion with
 * evidence.brand / evidence.brand_on_woo and caches the "brands to add" summary
 * under suggestions.brands_to_add. This page reads that cache and gives the
 * operator a one-click "Create on Woo" per brand.
 *
 * "Create on Woo" writes ONLY to products/brands (the WC-native taxonomy the
 * create-filter / TaxonomyResolver::allBrands() reads) — it does NOT touch
 * product_brand (publish handles the storefront link). No Claude spend, no
 * auto-create: strictly operator-triggered per brand.
 *
 * A "Refresh list" header action re-runs products:refresh-brands-to-add
 * (queued) so the operator can rebuild the summary after adding brands.
 *
 * RBAC: page + Create/Refresh actions gated to admin + pricing_manager
 * (mirrors CategoryAuditPage). sales / read_only cannot mount or create.
 *
 * navigationSort=16 — sits just after CategoryAuditPage (15) in Catalogue.
 */
final class BrandsToAddPage extends Page
{
    /** Cache key written by Piece 1 (RefreshBrandsToAddCommand::CACHE_KEY). */
    private const CACHE_KEY = RefreshBrandsToAddCommand::CACHE_KEY;

    protected static ?string $slug = 'brands-to-add';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'Brands to Add';

    protected static ?int $navigationSort = 16;

    protected static string $view = 'filament.pages.brands-to-add';

    protected static ?string $title = 'Brands to Add';

    /**
     * Brands to add, sorted by count desc. Each entry:
     *   ['brand'=>string, 'count'=>int, 'skus'=>array<int,string>]
     *
     * @var array<int, array{brand:string, count:int, skus:array<int,string>}>
     */
    public array $brands = [];

    /** ISO8601 timestamp of the last refresh (null = never refreshed). */
    public ?string $generatedAt = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public function mount(): void
    {
        $this->hydrateFromCache();
    }

    /**
     * Hydrate $brands + $generatedAt from the cached summary, sorted count desc.
     */
    private function hydrateFromCache(): void
    {
        $summary = Cache::get(self::CACHE_KEY);

        if (! is_array($summary)) {
            $this->brands = [];
            $this->generatedAt = null;

            return;
        }

        $this->generatedAt = is_string($summary['generated_at'] ?? null)
            ? $summary['generated_at']
            : null;

        $brands = array_values(array_filter(
            (array) ($summary['brands'] ?? []),
            fn ($b): bool => is_array($b) && ($b['brand'] ?? '') !== '',
        ));

        usort($brands, fn (array $a, array $b): int => (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));

        $this->brands = array_map(fn (array $b): array => [
            'brand' => (string) $b['brand'],
            'count' => (int) ($b['count'] ?? 0),
            'skus' => array_values((array) ($b['skus'] ?? [])),
        ], $brands);
    }

    /**
     * Header action — re-run products:refresh-brands-to-add (queued so it does
     * not block the request). admin + pricing_manager only.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh list')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Rebuild the brands-to-add list')
                ->modalDescription('Queues products:refresh-brands-to-add — re-walks pending suggestions, re-tags evidence.brand/brand_on_woo, and rebuilds this list. Reload the page in a few seconds once it finishes.')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                ->action(function (): void {
                    Artisan::queue('products:refresh-brands-to-add', []);

                    Notification::make()
                        ->success()
                        ->title('Refresh queued')
                        ->body('products:refresh-brands-to-add is running on the queue. Reload this page in a few seconds.')
                        ->send();
                }),
        ];
    }

    /**
     * Livewire action — create a single brand term on Woo via products/brands.
     *
     * Gated admin + pricing_manager (abort 403 otherwise — defence-in-depth
     * alongside the Blade @if that hides the button for non-writers).
     *
     * On success (or a graceful woocommerce_term_exists): forget the
     * taxonomy.brands cache (so TaxonomyResolver re-reads the new term), drop
     * the brand from BOTH the cached summary and $this->brands so the row
     * disappears, and notify. Any other Throwable → danger notification.
     */
    public function createBrand(string $brand): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false,
            403,
        );

        $brand = trim($brand);
        if ($brand === '') {
            return;
        }

        try {
            app(WooClient::class)->post('products/brands', ['name' => $brand]);

            $this->onBrandCreated($brand);

            Notification::make()
                ->success()
                ->title("Brand '{$brand}' created on Woo")
                ->body('Added to products/brands — the SKUs it unlocks are now creatable. Re-run the refresh to update the list.')
                ->send();
        } catch (\Throwable $e) {
            // A pre-existing term is a success for our purposes — the brand IS
            // on Woo, which is all Create-on-Woo guarantees. Woo surfaces this
            // as the woocommerce_term_exists error code / message.
            if ($this->isTermExists($e)) {
                $this->onBrandCreated($brand);

                Notification::make()
                    ->info()
                    ->title("Brand '{$brand}' already on Woo")
                    ->body('The term already existed on products/brands — treating as done and removing it from the list.')
                    ->send();

                return;
            }

            Notification::make()
                ->danger()
                ->title("Failed to create '{$brand}' on Woo")
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Remove a just-created brand from the cached summary + the in-memory list
     * so the row disappears without a full refresh.
     */
    private function onBrandCreated(string $brand): void
    {
        Cache::forget('taxonomy.brands');

        // Drop from the in-memory list (case-insensitive match).
        $this->brands = array_values(array_filter(
            $this->brands,
            fn (array $b): bool => mb_strtolower($b['brand']) !== mb_strtolower($brand),
        ));

        // Drop from the cached summary so a page reload before the next
        // scheduled refresh still shows the brand gone.
        $summary = Cache::get(self::CACHE_KEY);
        if (is_array($summary) && is_array($summary['brands'] ?? null)) {
            $summary['brands'] = array_values(array_filter(
                $summary['brands'],
                fn ($b): bool => is_array($b)
                    && mb_strtolower((string) ($b['brand'] ?? '')) !== mb_strtolower($brand),
            ));
            Cache::put(self::CACHE_KEY, $summary);
        }
    }

    /** True when the exception represents a "term already exists" Woo error. */
    private function isTermExists(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'woocommerce_term_exists')
            || str_contains(mb_strtolower($e->getMessage()), 'term_exists')
            || str_contains(mb_strtolower($e->getMessage()), 'already exists');
    }
}
