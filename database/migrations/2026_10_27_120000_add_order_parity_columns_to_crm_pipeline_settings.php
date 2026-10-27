<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12.1 Plan 02 (D-08) — plugin-parity config on the pipeline singleton.
 *
 * Additive, nullable, non-destructive:
 *   - deal_static_fields: JSON constants (TYPE_ID / OPENED / SOURCE_ID ...)
 *   - deal_cost_field / deal_predicted_margin_field: discovered UF_CRM_* codes
 *     (kept so the builder can refuse to emit them from static/mapping sources)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipeline_settings', function (Blueprint $t) {
            $t->json('deal_static_fields')->nullable();
            $t->string('deal_cost_field', 64)->nullable();
            $t->string('deal_predicted_margin_field', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_pipeline_settings', function (Blueprint $t) {
            $t->dropColumn(['deal_static_fields', 'deal_cost_field', 'deal_predicted_margin_field']);
        });
    }
};
