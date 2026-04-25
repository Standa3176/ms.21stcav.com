<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages;

use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 9 Plan 05 — CustomerGroupResource edit page (TRDE-04 D-10).
 *
 * DeleteAction is ->authorize() guarded (Warning 9 defence-in-depth). FK
 * ON DELETE RESTRICT (Plan 09-01) bubbles QueryException when the group has
 * active pricing rules — Filament's default error handler renders the message
 * to the operator. CustomerGroupResourceTest::it('deleting a CustomerGroup
 * with active pricing_rules raises a Filament-handled error') locks this.
 */
class EditCustomerGroup extends EditRecord
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }
}
