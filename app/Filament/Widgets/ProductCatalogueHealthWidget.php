<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 3 system-health tile: product-catalogue composition.
 *
 * Reads `product_catalogue_health` metric_key. 3 tiles — published / draft /
 * pending — matching the Woo post_status values.
 */
final class ProductCatalogueHealthWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function __construct()
    {
        static::$pollingInterval = (int) config('dashboard.widget_poll_seconds', 60) . 's';
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', DashboardSnapshot::class) ?? false;
    }

    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'product_catalogue_health')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Catalogue health', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $published = (int) ($payload['published'] ?? 0);
        $draft = (int) ($payload['draft'] ?? 0);
        $pending = (int) ($payload['pending'] ?? 0);
        $total = $published + $draft + $pending;

        // Threshold logic: published_pct ≥80 success, 50–80 warning, <50 danger.
        $publishedPct = $total > 0 ? ($published / $total) * 100 : 0;
        $publishedColor = $total === 0
            ? 'gray'
            : ($publishedPct >= 80 ? 'success' : ($publishedPct >= 50 ? 'warning' : 'danger'));

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Published', (string) $published)
                ->description('Live on meetingstore.co.uk')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($publishedColor)
                ->extraAttributes($ring),
            Stat::make('Draft', (string) $draft)
                ->description('Editor-side only')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('gray'),
            Stat::make('Pending', (string) $pending)
                ->description('Awaiting moderation')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($pending > 0 ? 'warning' : 'gray'),
        ];
    }
}
