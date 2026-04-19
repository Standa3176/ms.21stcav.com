<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — crm_status_mappings (D-06, CRM-07).
 *
 * Admin-editable map from Woo order status → Bitrix Deal STAGE_ID.
 * Seeded with the 7 standard Woo statuses on first migrate (via
 * CrmStatusMappingSeeder). Admin replaces `bitrix_stage_label` with a real
 * STAGE_ID through the Filament UI shipped in Plan 04-04.
 *
 * is_terminal: used by D-09's stage-change guard. A terminal→non-terminal
 * transition is disallowed (prevents accidental "un-cancel" pushes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_status_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('woo_status', 40)->unique();
            $t->string('bitrix_stage_id', 40)->nullable();    // resolved per pipeline
            $t->string('bitrix_stage_label', 40)->nullable(); // seeded label; admin replaces
            $t->boolean('is_terminal')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_status_mappings');
    }
};
