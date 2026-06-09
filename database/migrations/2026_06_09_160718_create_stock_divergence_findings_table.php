<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260609-nku — stock_divergence_findings snapshot table.
 *
 * Backs `products:audit-stock-divergence` (weekly Mon 09:15 London) which
 * TRUNCATEs and re-INSERTs the full set of phantom-stock findings every run.
 * Snapshot semantics (NOT history) — operator wants today's actionable list
 * of "Woo claims qty>0 but MS confirms qty=0 + every fresh supplier reports
 * qty=0", not a longitudinal trend.
 *
 * Phantom stock = woo_stock_quantity - ms_stock_quantity. ms_stock_quantity
 * is always 0 in current scope but stored for forensic clarity / future
 * extension to woo_undercount + woo_missing buckets.
 *
 * Driver-agnostic schema (no MySQL-only JSON columns) so the table runs on
 * in-memory SQLite for Pest while matching prod MySQL byte-for-byte.
 *
 * Indexes are tuned for the Filament page's read pattern:
 *   - sku: per-SKU lookups (table-action resync, search)
 *   - run_id: scopes the table to the LATEST run when future plans add history
 *   - status: drives bucket filters (today single-value 'woo_overcount')
 *   - phantom_units: supports the page's default ORDER BY phantom_units DESC
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_divergence_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64);
            $table->string('name')->nullable();
            $table->unsignedInteger('woo_product_id');
            $table->integer('ms_stock_quantity');
            $table->integer('woo_stock_quantity');
            $table->integer('phantom_units');
            $table->dateTime('woo_last_modified')->nullable();
            $table->dateTime('ms_last_synced_at')->nullable();
            $table->string('status', 32);
            $table->ulid('run_id');
            $table->dateTime('audited_at');
            $table->timestamps();

            $table->index('sku');
            $table->index('run_id');
            $table->index('status');
            $table->index('phantom_units');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_divergence_findings');
    }
};
