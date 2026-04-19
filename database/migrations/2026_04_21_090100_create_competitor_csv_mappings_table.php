<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — Per-competitor CSV column mapping (D-03 differentiator).
 *
 * One row per competitor (UNIQUE(competitor_id)). Populated on first-ingest by
 * ColumnHeuristicDetector; subsequent ingests consult it directly (fast path)
 * unless admin resets via Filament "Reset mapping" action. Ambiguity at
 * detection time writes a csv_parse_errors row + quarantines the CSV (D-04).
 *
 * - `sku_column_index` / `price_column_index` — 0-based column positions.
 * - `decimal_format` enum(dot|comma) — handles European decimal formats.
 * - `detected_at` — stamped by detector; surfaced in Filament for debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_csv_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competitor_id')
                ->constrained('competitors')
                ->cascadeOnDelete();
            $t->unsignedSmallInteger('sku_column_index');
            $t->unsignedSmallInteger('price_column_index');
            $t->enum('decimal_format', ['dot', 'comma'])->default('dot');
            $t->timestamp('detected_at');
            $t->timestamps();

            // D-03: one mapping per competitor
            $t->unique('competitor_id', 'competitor_csv_mappings_competitor_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_csv_mappings');
    }
};
