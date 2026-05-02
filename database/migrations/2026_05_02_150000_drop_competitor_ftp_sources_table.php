<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.2 Plan 01 — D-04 destructive: drop Phase 11.1's competitor_ftp_sources table.
 *
 * Phase 11.1 shipped a "1 row per FTP server" model. Operator feedback during the
 * same session: real-world setup is one shared FTP folder with 14+ competitor files.
 * Phase 11.2 replaces the schema wholesale with (shared credential) + (many feeds).
 *
 * Safe destructive drop — Phase 11.1 shipped 10 minutes ago, zero production data.
 * `down()` recreates the Phase 11.1 schema empty for rollback safety; operationally
 * we never roll this back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('competitor_ftp_sources');
    }

    public function down(): void
    {
        // Rollback safety — recreate Phase 11.1 schema empty so the migration is reversible.
        Schema::create('competitor_ftp_sources', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $t->string('name', 255);
            $t->string('protocol', 8)->default('sftp');
            $t->string('host', 255);
            $t->unsignedSmallInteger('port')->default(22);
            $t->string('username', 255);
            $t->text('password_encrypted')->nullable();
            $t->longText('private_key_encrypted')->nullable();
            $t->text('passphrase_encrypted')->nullable();
            $t->string('base_path', 512)->default('/');
            $t->string('filename_pattern', 512)->default('/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/');
            $t->string('cron_expression', 64)->default('*/15 * * * *');
            $t->boolean('verify_ssl')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('last_pulled_at')->nullable();
            $t->string('last_pull_status', 16)->nullable();
            $t->unsignedInteger('last_pull_files_fetched')->default(0);
            $t->text('last_pull_error')->nullable();
            $t->timestamps();
            $t->unique(['competitor_id', 'name'], 'competitor_ftp_sources_competitor_name_unique');
            $t->index(['is_active', 'last_pulled_at'], 'competitor_ftp_sources_active_lastpulled_idx');
        });
    }
};
