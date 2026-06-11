<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductResource\Pages;

use App\Domain\Products\Filament\Resources\ProductResource;
use App\Domain\Products\Models\Product;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Phase 6 Plan 04 — persist the Field Pins tab toggles after the main
     * Product save. The 8 pin_* booleans live in form state under
     * `override_pins.*`; this afterSave hook routes them to ProductOverride
     * via ProductResource::saveFieldPins which authorises + upserts the row.
     *
     * Quick task 260611-f1y — captures the pre-save value of
     * `is_internal_only` so afterSave() can detect a real toggle change
     * (not a no-op resave) and only then invoke the Woo push command.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Stash the override_pins away so Filament doesn't try to save them
        // as Product columns. Retrieved in afterSave().
        $this->pendingOverridePins = $data['override_pins'] ?? [];
        unset($data['override_pins']);

        // 260611-f1y — capture the pre-save value before Filament writes
        // the new one. We can't rely on $record->getOriginal() in afterSave()
        // because Filament refreshes the model after save which resets the
        // original-vs-current diff. Stash a plain bool here instead.
        /** @var Product $record */
        $record = $this->record;
        $this->preSaveIsInternalOnly = (bool) ($record->is_internal_only ?? false);

        return $data;
    }

    /** @var array<string, bool> */
    private array $pendingOverridePins = [];

    /**
     * 260611-f1y — pre-save state of is_internal_only captured in
     * mutateFormDataBeforeSave() and consumed in afterSave() to detect a
     * real toggle change vs. a no-op resave.
     */
    private bool $preSaveIsInternalOnly = false;

    protected function afterSave(): void
    {
        if ($this->pendingOverridePins !== []) {
            /** @var Product $record */
            $record = $this->record;
            ProductResource::saveFieldPins($record, $this->pendingOverridePins);
        }

        $this->pushVisibilityIfToggled();
    }

    /**
     * Quick task 260611-f1y — push catalog_visibility=hidden to Woo when
     * `is_internal_only` flips from false→true. No automatic re-enable on
     * the OFF path — the push command only sets `hidden`. Operator must
     * manually restore visibility on Woo OR a future task extends the
     * command with `--visibility=visible|hidden`. The notification text
     * explicitly surfaces this caveat so the operator isn't surprised.
     *
     * Token selection: prefer SKU when present; fall back to woo_product_id
     * (all 3 seeded internals have empty SKU, so woo_id is the only handle).
     */
    private function pushVisibilityIfToggled(): void
    {
        /** @var Product $product */
        $product = $this->record;

        $currentInternal = (bool) ($product->is_internal_only ?? false);

        if ($this->preSaveIsInternalOnly === $currentInternal) {
            // No change — no Woo I/O.
            return;
        }

        if ($product->woo_product_id === null) {
            Notification::make()
                ->title('is_internal_only flipped, but product has no woo_product_id — Woo push skipped')
                ->warning()
                ->send();

            return;
        }

        $token = $product->sku !== null && $product->sku !== ''
            ? (string) $product->sku
            : (string) $product->woo_product_id;

        try {
            Artisan::call('products:push-visibility-to-woo', [
                '--skus' => $token,
            ]);

            Notification::make()
                ->title($currentInternal
                    ? 'Pushed catalog_visibility=hidden to Woo'
                    : 'is_internal_only toggled OFF — Woo visibility NOT changed by command default. Re-enable visibility manually in Woo admin if needed.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Woo push failed: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
