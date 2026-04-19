<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — add `provider` column to sync_diffs.
 *
 * RESEARCH §Shadow-Mode Gate Option A — REUSE sync_diffs instead of a new
 * crm_push_shadow table. Adds a `provider` discriminator so Phase 7's
 * divergence scan can filter by provider (Woo vs Bitrix shadow writes).
 *
 * Additive + default 'woo' so existing Phase 1/2 rows implicitly migrate.
 * No UPDATE statement needed — the column default handles backfill.
 *
 * NOTE on naming collision: sync_diffs already has a `channel` column
 * (default 'woo'). `provider` is semantically distinct — channel is the
 * SDK surface being shadow-logged ('woo' | 'bitrix' | 'supplier'), while
 * provider is the target system ('woo' | 'bitrix'). In practice they'll
 * often duplicate, but keeping them separate preserves forward-compat
 * for multi-provider scenarios (e.g. Phase 5+ pushing to multiple CRMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_diffs', function (Blueprint $t) {
            $t->string('provider', 20)->default('woo')->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sync_diffs', function (Blueprint $t) {
            $t->dropIndex(['provider']);
            $t->dropColumn('provider');
        });
    }
};
