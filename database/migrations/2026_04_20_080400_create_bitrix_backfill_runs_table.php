<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — bitrix_backfill_runs (CRM-10).
 *
 * Progress tracker for `php artisan bitrix:backfill-orders` (Plan 04-05).
 * Mirrors the `sync_runs` shape from Phase 2 so ops UI patterns transfer.
 *
 * modes:
 *   - 'dry-run'              : default; no Bitrix writes
 *   - 'live'                 : real Bitrix pushes
 *   - 'adopt-legacy-deal-ids': Pitfall 5 one-shot — read Woo post_meta
 *                              _wc_bitrix24_deal_id, call dealUpdate to set
 *                              UF_CRM_WOO_ORDER_ID, write bitrix_entity_map rows
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix_backfill_runs', function (Blueprint $t) {
            $t->id();
            $t->date('since_date');
            $t->enum('mode', ['dry-run', 'live', 'adopt-legacy-deal-ids']);
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('total_orders')->default(0);
            $t->unsignedInteger('processed_orders')->default(0);
            $t->unsignedInteger('skipped_orders')->default(0);
            $t->unsignedInteger('failed_orders')->default(0);
            $t->unsignedInteger('adopted_legacy_count')->default(0);
            $t->string('last_cursor', 20)->nullable();
            $t->text('notes')->nullable();
            $t->string('correlation_id', 36)->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_backfill_runs');
    }
};
