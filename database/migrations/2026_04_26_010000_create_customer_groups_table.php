<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Plan 01 — customer_groups table (TRDE-01, D-01, D-02).
 *
 * Admin-managed lookup of B2B customer segments. Seeded with 4 groups by
 * Phase9\CustomerGroupSeeder (trade, reseller, education, nhs); admin can
 * deactivate via Filament Resource (D-10). Slug is the immutable join key
 * for config('b2b.role_to_group_map') and the FK target on pricing_rules
 * (Plan 09-01 second migration) and users (Plan 09-04).
 *
 * display_order drives Select dropdown ordering across the app (D-10) so
 * admins always see groups in a stable, intentional order. Indexed because
 * Filament's default list-view orderBy('display_order') runs on every
 * resource paint.
 *
 * is_active indexed for the Phase 9 RoleToGroupMapper / TradeRuleResolver
 * filter that excludes deactivated groups from rule resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('name', 128);
            $t->boolean('is_active')->default(true)->index();
            $t->unsignedSmallInteger('display_order')->default(100)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');
    }
};
