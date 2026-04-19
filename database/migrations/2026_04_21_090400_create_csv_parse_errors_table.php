<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — CSV ingest issues (COMP-05).
 *
 * Surfaces failures from the competitor-CSV pipeline in a Filament triage
 * page (CsvIngestIssuesPage, Plan 05-04). Nothing is silently dropped —
 * every parse problem has a row here with raw-line context.
 *
 * issue_type enum covers every failure mode from research C.3 gaps:
 * - ambiguous_mapping  — first-ingest column detection picked 0 or >1 candidates (D-04)
 * - encoding_failure   — BOM/mb_detect_encoding fallback exhausted
 * - unparseable_price  — non-numeric / currency-sign mismatch
 * - invalid_sku_format — SKU doesn't pass normalisation (empty, oversized)
 * - invalid_filename   — didn't match competitor filename_regex
 * - orphan_sku         — no Woo product matches; also fires new_product_opportunity
 *
 * FK nullability: `ingest_run_id` + `competitor_id` are nullable because
 * filename-level failures (invalid_filename) precede competitor resolution.
 *
 * (issue_type, resolved_at) composite index drives the Filament tabs:
 * unresolved-by-type is the ops triage surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_parse_errors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ingest_run_id')
                ->nullable()
                ->constrained('competitor_ingest_runs')
                ->nullOnDelete();
            $t->foreignId('competitor_id')
                ->nullable()
                ->constrained('competitors')
                ->nullOnDelete();
            $t->string('filename');
            $t->enum('issue_type', [
                'ambiguous_mapping',
                'encoding_failure',
                'unparseable_price',
                'invalid_sku_format',
                'invalid_filename',
                'orphan_sku',
            ]);
            $t->unsignedInteger('line_number')->nullable();
            $t->text('raw_line')->nullable();
            $t->json('context')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();

            // Filament tabs filter on (issue_type, resolved_at IS NULL)
            $t->index(['issue_type', 'resolved_at'], 'csv_parse_errors_type_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_parse_errors');
    }
};
