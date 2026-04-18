<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SyncRunResource\Pages;

use App\Domain\Sync\Filament\Resources\SyncRunResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;
}
