<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CsvParseErrorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CsvParseErrorResource;
use Filament\Resources\Pages\ListRecords;

class ListCsvParseErrors extends ListRecords
{
    protected static string $resource = CsvParseErrorResource::class;
}
