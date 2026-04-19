<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CsvParseErrorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CsvParseErrorResource;
use Filament\Resources\Pages\EditRecord;

class EditCsvParseError extends EditRecord
{
    protected static string $resource = CsvParseErrorResource::class;
}
