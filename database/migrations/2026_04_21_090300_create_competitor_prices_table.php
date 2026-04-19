<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — Competitor price history (COMP-07 dedup guarantee).
 *
 * NEVER TRUNCATED. The legacy Stock Updater plugin truncated this table
 * daily; full history is Phase 5's headline differentiator. Only raw CSV
 * source files under storage/app/competitors/archive/ prune after 90 days.
 *
 * Schema:
 * - `price_pennies_ex_vat` (BIGINT) — analyser input; removed UK VAT via
 *   App\Domain\Pricing\Services\PriceCalculator::stripVat (Phase 3 D-05).
 * - `price_pennies_gross` (BIGINT) — raw CSV value preserved for audit.
 * - `recorded_at` — CSV's row date OR ingest time if absent. Row uniqueness
 *   hinges on this column (a second CSV ingest with the same date is a no-op).
 * - `ingest_run_id` nullable — historical Woo backfill rows have no run.
 *
 * Indexes (COMP-07 + Filament trend-chart performance):
 * - UNIQUE(competitor_id, sku, recorded_at) — idempotent re-ingest of same CSV.
 * - (sku) — orphan detection: look up across competitors for a given SKU.
 * - (competitor_id, recorded_at) — trend chart per competitor.
 * - (recorded_at) — stale-feed + retention queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('competitor_id')
                ->constrained('competitors')
                ->cascadeOnDelete();
            $t->string('sku', 128);
            $t->string('mpn', 128)->nullable();
            $t->bigInteger('price_pennies_ex_vat')->unsigned();
            $t->bigInteger('price_pennies_gross')->unsigned();
            $t->timestamp('recorded_at');
            $t->foreignId('ingest_run_id')
                ->nullable()
                ->constrained('competitor_ingest_runs')
                ->cascadeOnDelete();
            $t->timestamps();

            // COMP-07 — idempotent re-ingest guarantee
            $t->unique(['competitor_id', 'sku', 'recorded_at'], 'competitor_prices_comp_sku_recorded_unique');

            // Orphan detection: "does SKU X appear anywhere?"
            $t->index('sku');

            // Per-competitor trend queries
            $t->index(['competitor_id', 'recorded_at'], 'competitor_prices_comp_recorded_idx');

            // Stale-feed + retention
            $t->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_prices');
    }
};
