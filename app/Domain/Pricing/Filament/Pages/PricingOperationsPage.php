<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Pages;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\CompetitorPositionScanner;
use App\Domain\Products\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pricing Operations — one screen for the core-loop pricing picture (built
 * 2026-05-25 on operator request: "a dashboard where we can check everything in
 * one place: price changes, SKUs added, competitor below floor, competitor below
 * our cost").
 *
 * Four panels:
 *   1. Recent sell-price changes  — day-over-day moves from product_price_snapshots.
 *   2. New SKUs awaiting review   — auto-drafted competitor-only products.
 *   3. Competitor at/below our floor — winnable but margin < floor (we hold at the floor).
 *   4. Competitor below our cost  — the unwinnable list (a supply problem).
 *
 * Panels 1-2 are cheap live queries. Panels 3-4 reuse CompetitorPositionScanner
 * (same ex-VAT margin math as pricing:floor-report), cached briefly so page
 * loads stay fast; the header "Recompute" action busts the cache.
 *
 * RBAC mirrors CompetitorAnalysisPage — admin + pricing_manager + sales can view
 * (CompetitorPrice viewAny); read_only is denied.
 */
class PricingOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-pound';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Pricing Operations';

    protected static ?string $title = 'Pricing Operations';

    protected static string $view = 'filament.pages.pricing-operations';

    protected static ?string $slug = 'pricing-operations';

    /** Cache key + TTL for the heavier competitor-position scan (panels 3-4). */
    private const SCAN_CACHE_KEY = 'pricing_ops:positions';

    private const SCAN_TTL_SECONDS = 900; // 15 min

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CompetitorPrice::class) ?? false;
    }

    /**
     * Header "Recompute" — bust the scan cache so panels 3-4 recompute on the
     * next render (the scan is the only expensive part).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recompute')
                ->label('Recompute positions')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    Cache::forget(self::SCAN_CACHE_KEY);
                    Notification::make()
                        ->success()
                        ->title('Recomputed competitor positions')
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $scan = Cache::remember(
            self::SCAN_CACHE_KEY,
            self::SCAN_TTL_SECONDS,
            static fn (): array => app(CompetitorPositionScanner::class)->compute(),
        );

        return [
            'recentChanges' => $this->recentPriceChanges(),
            'newSkus' => $this->newSkusAwaitingReview(),
            'scan' => $scan,
        ];
    }

    /**
     * Day-over-day sell-price changes from the daily snapshots: each product's
     * latest snapshot whose sell_price differs from the immediately-prior one,
     * most recent first. LAG/ROW_NUMBER window functions (MySQL 8 + SQLite ≥3.25).
     *
     * @return array<int, object>
     */
    private function recentPriceChanges(int $days = 30, int $limit = 50): array
    {
        $since = now()->subDays($days)->toDateString();

        return DB::select(
            'SELECT sku, sell_price AS new_price, prev_sell AS old_price, recorded_at FROM ('
            .'SELECT sku, sell_price, recorded_at, '
            .'LAG(sell_price) OVER (PARTITION BY product_id ORDER BY recorded_at) AS prev_sell, '
            .'ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY recorded_at DESC) AS rn '
            .'FROM product_price_snapshots WHERE recorded_at >= ?'
            .') t WHERE t.rn = 1 AND t.prev_sell IS NOT NULL AND t.sell_price <> t.prev_sell '
            .'ORDER BY t.recorded_at DESC, t.sku ASC LIMIT '.(int) $limit,
            [$since],
        );
    }

    /**
     * Auto-drafted products still awaiting manual review (the weekly
     * draft-competitor-skus output + any other auto-create drafts), newest first.
     *
     * @return Collection<int, Product>
     */
    private function newSkusAwaitingReview(int $limit = 50)
    {
        return Product::query()
            ->whereIn('auto_create_status', ['draft', 'pending_review', 'needs_brand_or_category_assignment'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'auto_create_status', 'sell_price', 'created_at']);
    }
}
