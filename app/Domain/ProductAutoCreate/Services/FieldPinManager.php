<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;

/**
 * Phase 6 Plan 04 — FieldPinManager (AUTO-10, AUTO-11 D-10, D-12).
 *
 * Thin service owning the 8 pin_* toggle upsert path. Lives in
 * ProductAutoCreate so that the Products layer doesn't need a Deptrac
 * allow for Pricing (ProductOverride is a Pricing-layer model).
 *
 * Called from:
 *   - ProductResource form `afterStateHydrated` — reads pins via `loadPinsFor`
 *   - EditProduct `afterSave` — writes pins via `savePins`
 *
 * Authorisation: `savePins` consults the active user's `update` gate on
 * ProductOverride (Phase 3 ProductOverridePolicy — admin + pricing_manager).
 * Unauthorised callers no-op silently.
 *
 * Audit trail: ProductOverride's LogsActivity trait (Phase 1 + Plan 06-01
 * extension) captures the before/after diff on every pin toggle.
 */
final class FieldPinManager
{
    public const PIN_COLUMNS = [
        'pin_title',
        'pin_short_description',
        'pin_long_description',
        'pin_meta_description',
        'pin_image',
        'pin_slug',
        'pin_brand',
        'pin_category',
    ];

    /**
     * Load current pin state for a product — returns an 8-key bool array.
     * Used by the Filament form's afterStateHydrated to populate the toggles.
     *
     * @return array<string, bool>
     */
    public function loadPinsFor(Product $product): array
    {
        $override = ProductOverride::firstOrNew(['product_id' => $product->id]);
        $state = [];
        foreach (self::PIN_COLUMNS as $col) {
            $state[$col] = (bool) ($override->{$col} ?? false);
        }

        return $state;
    }

    /**
     * Upsert the ProductOverride row with the 8 pin_* booleans from form state.
     *
     * Authorisation check (defence-in-depth on top of Filament's canEdit gate):
     * if the active user cannot `update` ProductOverride, this is a silent
     * no-op — the ProductOverridePolicy already returned 403 to the form, but
     * guarding here means a crafted Livewire payload cannot bypass.
     *
     * @param  array<string, bool>  $pins
     */
    public function savePins(Product $product, array $pins): bool
    {
        // Build the override first so the gate authorises against an INSTANCE —
        // ProductOverridePolicy::update(User, ProductOverride) requires the model
        // arg; a class-string would raise ArgumentCountError (pin-save 500).
        // The policy is role-only, so a non-persisted instance is fine here.
        $override = ProductOverride::firstOrNew(['product_id' => $product->id]);

        if (! auth()->user()?->can('update', $override)) {
            return false;
        }

        if (! $override->exists) {
            $override->created_by_user_id = auth()->id();
            $override->reason = 'Pin flags set via Filament Products Resource';
            // product_overrides.margin_basis_points is NOT NULL. A pins-only
            // override carries no margin change, so seed 0 — the same convention
            // App\Domain\Agents\Appliers\SeoContentPatchApplier uses when it
            // upserts an override solely for pin flags. Without this the INSERT
            // 500s on a product that has no pre-existing override.
            $override->margin_basis_points ??= 0;
        }

        foreach (self::PIN_COLUMNS as $col) {
            $override->{$col} = (bool) ($pins[$col] ?? false);
        }

        $override->save();

        return true;
    }
}
