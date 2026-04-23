<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 01 — add auto-create + SEO + completeness columns to products.
 *
 * Columns:
 *   slug                         → unique slug for Woo (D-05 deterministic)
 *   short_description            → Woo `short_description` (HTML <ul>)
 *   long_description             → Woo `description` (HTML 4-section)
 *   meta_description             → Woo SEO meta (≤160 chars, D-01)
 *   image_url                    → primary image URL (supplier or placeholder)
 *   requires_manual_image_review → Pitfall P6-A fallthrough flag
 *   auto_create_status           → 8-value state machine (D-09 publish gate)
 *   completeness_score           → D-07 weighted 0-100
 *   completeness_computed_at     → D-08 timestamp
 *   completeness_missing_fields  → D-08 JSON array of field names
 *
 * Pitfall P6-D: auto_create_status DEFAULT 'manual' + belt-and-braces backfill
 * so every pre-existing row (hundreds from Phase 2 + Phase 5) inherits 'manual'
 * — Filament review inbox filter auto_create_status IN ('draft','pending_review')
 * silently excludes them (correct), and no code path ever sees NULL on the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('slug', 255)->nullable()->after('name');
            $t->text('short_description')->nullable()->after('slug');
            $t->longText('long_description')->nullable()->after('short_description');
            $t->string('meta_description', 255)->nullable()->after('long_description');
            $t->string('image_url', 500)->nullable()->after('meta_description');
            $t->boolean('requires_manual_image_review')->default(false)->after('image_url');
            $t->enum('auto_create_status', [
                'manual',
                'draft',
                'pending_review',
                'approved',
                'published',
                'rejected',
                'needs_brand_or_category_assignment',
                'variations_not_supported_v1',
            ])->default('manual')->after('requires_manual_image_review')->index();
            $t->unsignedSmallInteger('completeness_score')->nullable()->after('auto_create_status');
            $t->timestamp('completeness_computed_at')->nullable()->after('completeness_score');
            $t->json('completeness_missing_fields')->nullable()->after('completeness_computed_at');
        });

        // Pitfall P6-D belt-and-braces backfill. The DEFAULT clause applies to
        // INSERT, but on some MySQL versions in rare scheduler conditions the
        // ADD COLUMN path has been observed to leave existing rows NULL; this
        // UPDATE guarantees 'manual' for every pre-existing row.
        DB::table('products')
            ->whereNull('auto_create_status')
            ->update(['auto_create_status' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn([
                'slug',
                'short_description',
                'long_description',
                'meta_description',
                'image_url',
                'requires_manual_image_review',
                'auto_create_status',
                'completeness_score',
                'completeness_computed_at',
                'completeness_missing_fields',
            ]);
        });
    }
};
