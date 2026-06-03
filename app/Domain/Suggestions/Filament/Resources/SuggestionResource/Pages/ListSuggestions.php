<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListSuggestions extends ListRecords
{
    protected static string $resource = SuggestionResource::class;
}
