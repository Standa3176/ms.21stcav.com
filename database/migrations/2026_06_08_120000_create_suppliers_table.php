<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260608-g8x — first-class `suppliers` table.
 *
 * NOTE: prior to this migration there was NO local `suppliers` table at all.
 * The `supplier_offer_snapshots.supplier_id` column is a VARCHAR(16) raw text
 * reference into the remote supplier feed's `feeds.id` value — no FK existed.
 *
 * This table acquires per-supplier metadata locally so the operator can:
 *   - override `stale_after_days` per supplier (e.g. Nuvias = 21 days because
 *     they upload erratically; default for everyone else stays 7)
 *   - dormant a supplier without deleting (is_active=false)
 *   - leave free-form notes for context (manager handovers etc.)
 *
 * Populated on first + every subsequent run of `suppliers:check-stale` by
 * discovering distinct supplier_ids in `supplier_offer_snapshots` and
 * upserting one row per. The `supplier_id` column is the natural key — a
 * VARCHAR(16) matching the snapshot column type byte-for-byte. NO FK to
 * `supplier_offer_snapshots` (snapshot row would not exist when the
 * suppliers row is first INSERTed by --dry-run / fresh-environment paths).
 *
 * Index on `is_active` because the dashboard tile counts only active rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            // Matches `supplier_offer_snapshots.supplier_id` width (16) byte-for-byte.
            $table->string('supplier_id', 16)->unique();
            // Denormalised display name — latest from supplier_offer_snapshots.supplier_name.
            $table->string('name', 100)->nullable();
            // Per-supplier override; NULL falls back to config('supplier.default_stale_after_days').
            $table->unsignedSmallInteger('stale_after_days')->nullable();
            // Operator can dormant a supplier without deleting.
            $table->boolean('is_active')->default(true);
            // Operator notes for context / manager handovers.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
