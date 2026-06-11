<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quick task 260611-f1y — seed `is_internal_only=true` for the 3 known
 * internal-use products surfaced by today's Phase 7 prod gap-review probe.
 *
 * Seeded products (woo_product_id):
 *   167493 — Credit                (storefront symptom: tile appears on shop / search results)
 *   167492 — Offer                 (storefront symptom: tile appears on shop / search results)
 *   165038 — Quote Payment (No Vat) (storefront symptom: tile appears on shop / search results)
 *
 * Customers see 3 confusing internal-use tiles when browsing
 * meetingstore.co.uk/shop. They are needed for direct-URL custom-quote
 * attach (orderable), so the fix is `catalog_visibility=hidden` (hide from
 * discovery, preserve direct-URL ordering) — pushed by the artisan
 * command `products:push-visibility-to-woo` after this migration lands.
 *
 * WHY woo_product_id (not sku): all 3 internal products have empty SKU. A
 * SKU-based seed would silently match every other empty-SKU row (e.g. the
 * Dell 8K monitor false positive at woo=7317 surfaced in the 2026-06-11
 * probe).
 *
 * Idempotent: `up()` skips rows already flagged so re-running is a true
 * no-op rather than incurring redundant updated_at bumps. `down()` reverses
 * the flags safely.
 *
 * NOT a Woo write: data-only. The push to Woo is the artisan command
 * the operator runs AFTER deploy.
 */
return new class extends Migration
{
    /** @var array<int, int> */
    private array $internalWooProductIds = [
        167493, // Credit
        167492, // Offer
        165038, // Quote Payment (No Vat)
    ];

    public function up(): void
    {
        DB::table('products')
            ->whereIn('woo_product_id', $this->internalWooProductIds)
            ->where(function ($q): void {
                // True idempotency — only touch rows whose flag is not already
                // true. Both NULL (legacy default) and false count as "needs flip".
                $q->whereNull('is_internal_only')
                    ->orWhere('is_internal_only', '!=', true);
            })
            ->update([
                'is_internal_only' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('products')
            ->whereIn('woo_product_id', $this->internalWooProductIds)
            ->update([
                'is_internal_only' => false,
                'updated_at' => now(),
            ]);
    }
};
