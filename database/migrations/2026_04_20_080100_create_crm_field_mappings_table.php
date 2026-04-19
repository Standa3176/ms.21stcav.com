<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 01 — crm_field_mappings (CRM-06).
 *
 * Admin-editable map from Woo order/customer fields → Bitrix Deal/Contact/Company
 * fields. Seeded from the legacy itgalaxy plugin's mappings in Plan 04-04; this
 * migration ships the schema + model + policy only.
 *
 * Transformer column: optional string reference to a named transformer
 * (e.g. 'uppercase', 'phone_e164', 'join_line_items', 'none'). Resolved
 * at push time by a TransformerRegistry (Plan 04-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_field_mappings', function (Blueprint $t) {
            $t->id();
            $t->enum('entity_type', ['deal', 'contact', 'company']);
            $t->string('woo_field', 100);            // 'billing.first_name' | '_ms_utm_source' | 'line_items'
            $t->string('bitrix_field', 100);         // 'NAME' | 'UF_CRM_WOO_UTM_SOURCE'
            $t->boolean('is_custom')->default(false); // true for UF_CRM_* Bitrix fields
            $t->string('transformer', 60)->nullable(); // 'uppercase' | 'phone_e164' | 'none'
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['entity_type', 'woo_field'], 'crm_field_mappings_type_field_unique');
            $t->index(['entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_field_mappings');
    }
};
