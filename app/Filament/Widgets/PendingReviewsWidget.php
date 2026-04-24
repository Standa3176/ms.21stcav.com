<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 2 actions tile: pending reviews.
 *
 * Reads `pending_reviews` metric_key (auto-create drafts + margin-change +
 * new-product-opportunity suggestions pending). Each tile deep-links to the
 * filtered inbox for that kind.
 */
final class PendingReviewsWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'pending_reviews')->first();

        if ($snapshot === null) {
            return [
                Stat::make('Pending reviews', '—')
                    ->description('Awaiting first dashboard:refresh')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $drafts = (int) ($payload['auto_create_drafts'] ?? 0);
        $margin = (int) ($payload['margin_change_suggestions'] ?? 0);
        $opportunity = (int) ($payload['new_product_opportunity_suggestions'] ?? 0);

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Auto-create drafts', (string) $drafts)
                ->description('Ready for human review')
                ->color($drafts > 0 ? 'warning' : 'gray')
                ->extraAttributes($ring),
            Stat::make('Margin changes', (string) $margin)
                ->description('Pending pricing adjustments')
                ->color($margin > 0 ? 'warning' : 'gray'),
            Stat::make('New product opportunities', (string) $opportunity)
                ->description('Competitor-surfaced candidates')
                ->color($opportunity > 0 ? 'warning' : 'gray'),
        ];
    }
}
