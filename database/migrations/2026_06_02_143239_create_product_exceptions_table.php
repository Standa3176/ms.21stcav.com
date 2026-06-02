<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-managed allowlist for SKUs that must STAY status=publish on Woo
 * even when the supplier sync would normally demote them.
 *
 * Background: FlagProductsMissingBuyPriceCommand (Mon-Fri 07:15) demotes
 * publish → pending whenever supplier_db doesn't carry the SKU
 * (buy_price is NULL/0). That's the right default for most of the
 * catalogue — stale prices shouldn't show to customers. But:
 *
 *   - In-house assembled products (already partially handled by the
 *     'custom-ms' tag) need to stay live.
 *   - Products sourced from non-integrated suppliers (manual orders,
 *     one-off vendors) need to stay live.
 *   - Strategic loss-leaders / special agreements need operator-pinned
 *     status.
 *
 * This table lets the operator explicitly whitelist those SKUs via a
 * Filament UI. is_paused gives a non-destructive "temporarily ignore
 * this rule" toggle without deleting the row (preserves audit + reason).
 *
 * sku is unique + indexed — looked up per row in the daily sync. Reason
 * + notes capture the operator's rationale (audit + handoff context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_exceptions', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 64)->unique();
            $t->string('reason', 255)->nullable();         // short label (e.g. "Custom build", "ItGalaxy direct")
            $t->boolean('is_paused')->default(false);      // temporarily ignore without delete
            $t->text('notes')->nullable();                  // long-form context
            $t->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $t->timestamps();

            $t->index(['is_paused']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_exceptions');
    }
};
