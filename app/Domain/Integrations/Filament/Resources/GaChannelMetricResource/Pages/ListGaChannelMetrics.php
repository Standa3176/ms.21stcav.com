<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources\GaChannelMetricResource\Pages;

use App\Domain\Integrations\Filament\Resources\GaChannelMetricResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 15 Plan 15a-02 — GA4 Channels list page (READ-ONLY).
 *
 * No header create action — the table is populated exclusively by the
 * scheduled google:pull-ga4 pull.
 */
class ListGaChannelMetrics extends ListRecords
{
    protected static string $resource = GaChannelMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
