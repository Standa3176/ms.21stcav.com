<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoPublishLogResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoPublishLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Quick task 260711-aps Task 3 — Auto-Publish Log list page (READ-ONLY).
 *
 * No header create action — the table is populated exclusively by the scheduled
 * products:draft-from-suggestions --auto-approve run.
 */
class ListAutoPublishLog extends ListRecords
{
    protected static string $resource = AutoPublishLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
