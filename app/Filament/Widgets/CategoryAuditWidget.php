<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260607-t6w — Home dashboard tile: Category Audit.
 *
 * Surfaces the latest weekly audit snapshot's findings count + per-issue
 * breakdown with click-through to /admin/category-audit for triage.
 *
 * Reads from the `category_audit_health` snapshot key populated by
 * SnapshotAggregator::computeCategoryAuditHealth during dashboard:refresh
 * (5-min cadence) — widget render path NEVER hits category_audit_findings
 * directly, so the dashboard stays a single indexed lookup per tile.
 *
 * Color logic:
 *   - success (green): total = 0 — clean catalogue
 *   - danger (red):    any 'missing' findings exist (Google Shopping
 *                      disapprovals; Severity 1)
 *   - warning (amber): other findings exist (orphaned / uncategorized /
 *                      suspicious — Severity 2-4, less urgent)
 *
 * RBAC: admin + pricing_manager only. Sales / read_only see silent absence
 * (the dashboard layout adapts; no 403). Mirrors
 * AdCandidatesReadyWidget's pattern.
 */
final class CategoryAuditWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'category_audit_health')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $total = (int) ($payload['total'] ?? 0);
        $missing = (int) ($payload['missing'] ?? 0);
        $orphaned = (int) ($payload['orphaned'] ?? 0);
        $uncategorized = (int) ($payload['uncategorized'] ?? 0);
        $suspicious = (int) ($payload['suspicious'] ?? 0);

        $color = $total === 0
            ? 'success'
            : ($missing > 0 ? 'danger' : 'warning');

        return [
            Stat::make('Category Audit', number_format($total))
                ->description(sprintf(
                    '%d missing · %d orphaned · %d uncategorized · %d suspicious',
                    $missing,
                    $orphaned,
                    $uncategorized,
                    $suspicious,
                ))
                ->descriptionIcon('heroicon-m-tag')
                ->color($color)
                ->url('/admin/category-audit'),
        ];
    }
}
