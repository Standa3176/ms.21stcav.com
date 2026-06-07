<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260607-t6w — category_audit_findings snapshot table.
 *
 * Backs `products:audit-categories` (Weekly Fri 22:00 London) which TRUNCATEs
 * and re-INSERTs the full set of misclassified-live-product findings every
 * run. Snapshot semantics (NOT history) — operator wants today's actionable
 * list, not a longitudinal trend.
 *
 * Driver-agnostic schema (no MySQL-only JSON columns) so the table runs on
 * in-memory SQLite for Pest while matching prod MySQL byte-for-byte.
 *
 * Indexes are tuned for the Filament page's read pattern:
 *   - run_id: scopes the table to the LATEST run (subquery filter)
 *   - issue_type: drives the SelectFilter on the page header
 *   - (severity, audited_at): supports default ORDER BY severity ASC, audited_at DESC
 *   - product_id: explicit (foreignId already adds an index on MySQL, but
 *     SQLite test env needs it spelled out for the cascade FK)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->ulid('run_id');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name')->default('');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category_name')->default('');
            $table->string('category_root_name')->nullable();
            $table->string('issue_type', 32);
            $table->unsignedTinyInteger('severity');
            $table->timestamp('audited_at');
            $table->timestamps();

            $table->index('run_id');
            $table->index('issue_type');
            $table->index(['severity', 'audited_at']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_audit_findings');
    }
};
