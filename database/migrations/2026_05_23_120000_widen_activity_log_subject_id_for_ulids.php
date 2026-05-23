<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_log.subject_id must hold ULID PKs (e.g. CompetitorFtpCredential),
 * not just integers. The default spatie/activitylog migration types it as
 * unsignedBigInteger, so MySQL truncates 26-char ULIDs ("SQLSTATE[01000]:
 * Data truncated for column 'subject_id'") — which surfaced as a 500 when
 * testing a Competitor FTP credential. SQLite (local dev) is loosely typed,
 * so it never showed there. Widen to a string that fits integer IDs, ULIDs
 * (26) and UUIDs (36) alike; the morph index adapts and int IDs compare fine
 * as strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->string('subject_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Lossy if any ULID/UUID subjects exist; provided for completeness.
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
