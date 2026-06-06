<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260606-lhp — Tile 1: "High-confidence sourceable opportunities".
 *
 * Reads the `suggestions_triage_health` snapshot key (populated by
 * SnapshotAggregator::computeSuggestionsTriageHealth() during dashboard:refresh
 * every 5 min). high_confidence_count is the headline number — same value the
 * /admin/suggestions sidebar badge shows (drift-locked via the shared
 * Suggestion::scopeHighConfidenceSourceable scope).
 *
 * Description renders the 3-tier breakdown (high-confidence • sourceable •
 * raw-pending) so the operator sees what's filtered out at a glance.
 *
 * Click-through deep-links to /admin/suggestions with the four existing
 * SelectFilters pre-applied (kind=new_product_opportunity, status=pending,
 * competitor_count_bucket=3plus, on_supplier_db=yes). Filter chips are
 * visible above the table after the redirect.
 *
 * RBAC: admin + pricing_manager only. Sales / read_only see silent absence
 * (the dashboard layout adapts; no 403). Mirrors IntegrationHealthWidget's
 * pattern.
 */
final class HighConfidenceSourceableWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'suggestions_triage_health')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $highConfidence = (int) ($payload['high_confidence_count'] ?? 0);
        $sourceable = (int) ($payload['sourceable_count'] ?? 0);
        $rawPending = (int) ($payload['raw_pending_count'] ?? 0);

        // Filament 3 SelectFilter form-state: tableFilters[<name>][value]=<v>.
        // SuggestionResource defines exactly these four SelectFilter::make()
        // names — keep this set in sync if a filter is renamed there.
        $filterUrl = '/admin/suggestions?'.http_build_query([
            'tableFilters' => [
                'kind' => ['value' => 'new_product_opportunity'],
                'status' => ['value' => 'pending'],
                'competitor_count_bucket' => ['value' => '3plus'],
                'on_supplier_db' => ['value' => 'yes'],
            ],
        ]);

        $stale = $snapshot?->computed_at !== null && $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('High-confidence sourceable opportunities', number_format($highConfidence))
                ->description(sprintf(
                    '%s sourceable • %s raw pending',
                    number_format($sourceable),
                    number_format($rawPending),
                ))
                ->descriptionIcon('heroicon-m-sparkles')
                ->color($highConfidence >= 1 ? 'success' : 'gray')
                ->url($filterUrl)
                ->extraAttributes($ring),
        ];
    }
}
