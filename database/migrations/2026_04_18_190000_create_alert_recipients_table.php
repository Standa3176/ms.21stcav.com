<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-12 new scope: DB-backed alert distribution list.
 *
 * Replaces the static FAILED_JOB_EMAILS env var approach. Admins manage
 * recipients via Filament AlertRecipientResource (admin-only — Pitfall K).
 *
 * Seeded with ops@meetingstore.co.uk fallback by AlertRecipientSeeder
 * (Pitfall M — empty list would cause silent alert outages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_recipients', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_recipients');
    }
};
