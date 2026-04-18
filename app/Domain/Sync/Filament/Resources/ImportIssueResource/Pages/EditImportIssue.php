<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\ImportIssueResource\Pages;

use App\Domain\Sync\Filament\Resources\ImportIssueResource;
use Filament\Resources\Pages\EditRecord;

class EditImportIssue extends EditRecord
{
    protected static string $resource = ImportIssueResource::class;
}
