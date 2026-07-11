<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260711-aps Task 2 — auto_publish_log table.
 *
 * "Keep a record of what was pushed and when." One row per REAL successful
 * scheduled auto-publish (straight-to-live Woo). Written by the
 * products:draft-from-suggestions --auto-approve loop AFTER PublishProductJob
 * confirms the product is published (auto_create_status='published' AND
 * woo_product_id present). In shadow mode (WOO_WRITE_ENABLED=false) NOTHING is
 * published, so NO row is written.
 *
 * competitor_count captures the driving suggestion's supporting_competitors (2
 * or 3 under the twice-weekly schedule) so the operator sees the split.
 * supplier_count is forward-compat (nullable — the current walk records null).
 *
 * published_at is the authoritative timestamp (no created_at/updated_at, matching
 * the append-only audit convention). Driver-portable (SQLite tests / MariaDB
 * prod — memory sqlite-mariadb-strict-trap): plain Blueprint column types + two
 * plain indexes for the viewer's default sort + competitor filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_publish_log', function (Blueprint $t): void {
            $t->id();
            $t->string('sku');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('woo_product_id')->nullable();
            $t->unsignedInteger('competitor_count');
            $t->unsignedInteger('supplier_count')->nullable();
            $t->string('source')->default('scheduled_auto_publish');
            $t->string('batch_correlation_id')->nullable();
            $t->timestamp('published_at');
            $t->index('published_at');
            $t->index('competitor_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_publish_log');
    }
};
