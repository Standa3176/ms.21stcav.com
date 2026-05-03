<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource\Pages;

use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIntegrationCredential extends EditRecord
{
    protected static string $resource = IntegrationCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Phase 09.1 — payload_encrypted is reset to an empty array on the form
     * so the dehydrated(filled) on each sub-field works ("blank means keep").
     * Without this, Filament would pre-fill the existing decrypted values
     * which both leaks plaintext to the form HTML AND makes blank-on-edit
     * semantics impossible to express.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['payload_encrypted'] = [];

        return $data;
    }

    /**
     * On save, merge any blank sub-fields with the EXISTING payload — preserves
     * "blank means keep" semantics by re-attaching values from the model row
     * for any field the admin didn't fill in.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existing = (array) ($this->record->payload_encrypted ?? []);
        $form = (array) ($data['payload_encrypted'] ?? []);

        $data['payload_encrypted'] = array_merge($existing, array_filter($form, fn ($v) => filled($v)));

        return $data;
    }
}
