<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Header widget on the CsvParseErrorResource list page (2026-05-31).
 *
 * Renders one stat tile per competitor with the unresolved-parse-error count
 * + the dominant issue_type for that competitor, sorted by count DESC. Lets
 * ops see which competitor's feed is the biggest source of ingest pain at a
 * glance instead of having to filter the table.
 *
 * Orphan-SKU rows (where competitor_id is null) are bucketed under "(orphan)"
 * so they remain visible. Capped at 8 tiles to keep the header tidy; the rest
 * fold into a "(N more competitors)" tile.
 */
final class CsvParseErrorsByCompetitorWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        // One row per competitor: count + array of (issue_type, count) for the dominant type.
        $rows = DB::table('csv_parse_errors')
            ->leftJoin('competitors', 'competitors.id', '=', 'csv_parse_errors.competitor_id')
            ->whereNull('csv_parse_errors.resolved_at')
            ->selectRaw('COALESCE(competitors.name, "(orphan)") as competitor_name')
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(issue_type ORDER BY issue_type), ",", 1) as sample_type')
            ->groupBy('competitor_name')
            ->orderByDesc('n')
            ->get();

        if ($rows->isEmpty()) {
            return [
                Stat::make('CSV parse errors', '0')
                    ->description('No unresolved errors — clean.')
                    ->descriptionIcon('heroicon-m-check-circle')
                    ->color('success'),
            ];
        }

        $tiles = [];
        $cap = 8;
        $shown = 0;
        $hiddenCount = 0;
        $hiddenTotal = 0;

        foreach ($rows as $row) {
            if ($shown >= $cap) {
                $hiddenCount++;
                $hiddenTotal += (int) $row->n;

                continue;
            }
            $shown++;
            $tiles[] = Stat::make((string) $row->competitor_name, (string) $row->n)
                ->description('Top: '.str_replace('_', ' ', (string) $row->sample_type))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color((int) $row->n > 50 ? 'danger' : ((int) $row->n > 10 ? 'warning' : 'gray'));
        }

        if ($hiddenCount > 0) {
            $tiles[] = Stat::make("+{$hiddenCount} more competitors", (string) $hiddenTotal)
                ->description('Combined unresolved')
                ->descriptionIcon('heroicon-m-ellipsis-horizontal')
                ->color('gray');
        }

        return $tiles;
    }
}
