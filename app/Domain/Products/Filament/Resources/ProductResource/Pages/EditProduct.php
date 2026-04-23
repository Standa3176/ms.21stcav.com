<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductResource\Pages;

use App\Domain\Products\Filament\Resources\ProductResource;
use App\Domain\Products\Models\Product;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Phase 6 Plan 04 — persist the Field Pins tab toggles after the main
     * Product save. The 8 pin_* booleans live in form state under
     * `override_pins.*`; this afterSave hook routes them to ProductOverride
     * via ProductResource::saveFieldPins which authorises + upserts the row.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Stash the override_pins away so Filament doesn't try to save them
        // as Product columns. Retrieved in afterSave().
        $this->pendingOverridePins = $data['override_pins'] ?? [];
        unset($data['override_pins']);

        return $data;
    }

    /** @var array<string, bool> */
    private array $pendingOverridePins = [];

    protected function afterSave(): void
    {
        if ($this->pendingOverridePins === []) {
            return;
        }

        /** @var Product $record */
        $record = $this->record;
        ProductResource::saveFieldPins($record, $this->pendingOverridePins);
    }
}
