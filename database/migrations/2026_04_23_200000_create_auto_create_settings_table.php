<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 04 — auto_create_settings (D-07, AUTO-07).
 *
 * Singleton table (exactly one row) holding the admin-controlled auto-create
 * config — mode (draft / immediate_publish), CTA trailer, optimize_images,
 * completeness_threshold.
 *
 * Values here OVERRIDE config/product_auto_create.php defaults via the
 * AutoCreateSetting::resolve() accessor. Draft-first v1 lock (AUTO-07) is
 * enforced by seeding mode='draft' on install.
 *
 * Pattern matches Phase 4 Plan 01's crm_pipeline_settings singleton table
 * (one row, firstOrFail() accessor). Admin-only write gate via
 * AutoCreateSettingsPolicy + Page canAccess().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_create_settings', function (Blueprint $t): void {
            $t->id();
            $t->enum('mode', ['draft', 'immediate_publish'])->default('draft');
            $t->string('cta', 255)->default('Shop now at meetingstore.co.uk');
            $t->boolean('optimize_images')->default(true);
            $t->unsignedTinyInteger('completeness_threshold')->default(85);
            $t->timestamps();
        });

        // Singleton row — guarantees AutoCreateSetting::current() always
        // resolves. Defaults mirror config/product_auto_create.php so the
        // first admin save is a no-op semantically.
        DB::table('auto_create_settings')->insert([
            'mode' => 'draft',
            'cta' => 'Shop now at meetingstore.co.uk',
            'optimize_images' => PHP_OS_FAMILY !== 'Windows',
            'completeness_threshold' => 85,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_create_settings');
    }
};
