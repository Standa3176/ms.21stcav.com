<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SupplierResource\Pages;

use App\Domain\Sync\Filament\Resources\SupplierResource;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * Quick task 260626-oqr — Warning 9 defence-in-depth: hard-gate the save
     * at POST so a crafted request from sales/read_only can't persist edits
     * even though every form field is ->disabled for them. Mirrors the
     * ImportIssueResource bulk-action ->authorize convention.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false,
            403,
        );

        return $data;
    }
}
