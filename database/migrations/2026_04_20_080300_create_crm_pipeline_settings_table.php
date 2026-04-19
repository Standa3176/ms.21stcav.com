<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — crm_pipeline_settings (D-05, D-07, CRM-07).
 *
 * Singleton table (exactly one row) holding the admin-picked Bitrix pipeline
 * + landing stage. Legacy parity: one pipeline per Woo store; CRM-07 is met
 * by admin choice, not rule-driven routing.
 *
 * Seeded on migration with a default deal_title_template so Plan 04-03's
 * DealPayloadBuilder has a string to render against even before an admin
 * opens the settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipeline_settings', function (Blueprint $t) {
            $t->id();
            $t->string('bitrix_pipeline_id', 20)->nullable();   // CATEGORY_ID
            $t->string('landing_stage_id', 40)->nullable();     // initial stage on deal creation
            $t->string('assigned_user_id', 20)->nullable();     // default responsible user
            $t->string('deal_title_template', 255)->default('Woo Order #{order_number}');
            $t->timestamps();
        });

        // Singleton row — guarantees CrmPipelineSetting::current() always resolves.
        DB::table('crm_pipeline_settings')->insert([
            'deal_title_template' => 'Woo Order #{order_number}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_settings');
    }
};
