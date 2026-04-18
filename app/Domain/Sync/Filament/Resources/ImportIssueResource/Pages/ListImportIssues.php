<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\ImportIssueResource\Pages;

use App\Domain\Sync\Filament\Resources\ImportIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListImportIssues extends ListRecords
{
    protected static string $resource = ImportIssueResource::class;
}
