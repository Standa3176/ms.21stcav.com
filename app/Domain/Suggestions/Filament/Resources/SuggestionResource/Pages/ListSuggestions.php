<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListSuggestions extends ListRecords
{
    protected static string $resource = SuggestionResource::class;

    // Quick task 260707-iz9 — one-line explainer of what this section is and
    // the intended workflow (check Readiness → Auto-create the Ready ones).
    public function getSubheading(): ?string
    {
        return 'Products your competitors sell that you may not — check Readiness, then Auto-create the Ready ones.';
    }
}
