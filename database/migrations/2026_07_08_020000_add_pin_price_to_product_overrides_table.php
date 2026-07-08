<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260708-gy0 — add the missing pin_price column to product_overrides.
 *
 * Latent prod bug: ProductOverrideGuard's FIELD_MAP has mapped
 * `regular_price → ['pin' => 'pin_price', 'local' => 'sell_price']` since
 * Phase 6 Plan 03, but the pin_price column was never migrated — the Phase 6
 * Plan 01 migration only added the other 8 pin_* flags. Result: price-pinning
 * was silently a no-op on supplier sync (the guard read `null` for pin_price
 * and never re-asserted the local price). This adds the column so the mapping
 * actually enforces.
 *
 * Defaults FALSE; existing rows inherit the default (belt-and-braces backfill
 * mirrors the 2026_04_22 pin-columns migration for MySQL edge-case parity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_overrides', function (Blueprint $t): void {
            $t->boolean('pin_price')->default(false)->after('pin_image');
        });

        DB::table('product_overrides')->update(['pin_price' => false]);
    }

    public function down(): void
    {
        Schema::table('product_overrides', function (Blueprint $t): void {
            $t->dropColumn('pin_price');
        });
    }
};
