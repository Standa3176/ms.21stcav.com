<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 01 — add 8 pin_* columns to product_overrides (D-10).
 *
 * Extends the Phase 3 Plan 01 product_overrides table. Each pin_* bool
 * indicates "field is human-edited — sync must not overwrite" per AUTO-10.
 *
 * pin_title + pin_short_description + pin_long_description + pin_meta_description
 *   → Phase 2 SupplierPriceChanged/SupplierStockChanged listener checks these
 *     via ProductOverrideGuard before overwriting Product fields (Plan 06-03).
 *
 * pin_image  → image pipeline Plan 06-02 respects this on re-sync.
 * pin_slug   → slug regeneration Plan 06-03 skips if pinned.
 * pin_brand / pin_category → TaxonomyResolver Plan 06-03 skips re-resolve.
 *
 * All columns default FALSE; existing product_overrides rows (Phase 3) inherit
 * the default — no explicit backfill needed. The DEFAULT clause applies to
 * ADD COLUMN for all existing rows.
 *
 * D-12 audit trail: ProductOverride::getActivitylogOptions is extended in the
 * model to include pin_* columns so every toggle lands in activity_log.
 */
return new class extends Migration
{
    private const PIN_COLUMNS = [
        'pin_title',
        'pin_short_description',
        'pin_long_description',
        'pin_meta_description',
        'pin_image',
        'pin_slug',
        'pin_brand',
        'pin_category',
    ];

    public function up(): void
    {
        Schema::table('product_overrides', function (Blueprint $t): void {
            foreach (self::PIN_COLUMNS as $col) {
                $t->boolean($col)->default(false)->after('reason');
            }
        });

        // Belt-and-braces backfill — DEFAULT applies to ADD COLUMN but we
        // explicitly set each pin to false on any pre-existing row (MySQL
        // edge-case parity with Pitfall P6-D).
        DB::table('product_overrides')->update(array_fill_keys(self::PIN_COLUMNS, false));
    }

    public function down(): void
    {
        Schema::table('product_overrides', function (Blueprint $t): void {
            $t->dropColumn(self::PIN_COLUMNS);
        });
    }
};
