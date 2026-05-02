<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.1 Plan 01 — D-03 admin-managed FTP/SFTP/FTPS competitor feed sources.
 *
 * 19-column schema with ULID PK (CHAR(26) — Phase 1 D-14 ULID convention),
 * cascade FK to competitors (Phase 5), 3 encrypted credential columns
 * (D-04 — `'encrypted'` Eloquent cast applies AES-256 at the model layer),
 * verify_ssl + is_active toggles, and a 3-strike circuit-breaker counter
 * (D-12 — `consecutive_failures`).
 *
 * Composite UNIQUE (competitor_id, name) prevents two sources with the same
 * friendly name under one competitor (one Cisco "weekly_csv" only).
 *
 * Index (is_active, last_pulled_at) makes the every-15-min pull's "next
 * source to pull" query covered.
 *
 * Encrypted credentials: APP_KEY rotation invalidates ALL rows; the
 * CompetitorFtpSource model docblock documents the operator runbook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_ftp_sources', function (Blueprint $t): void {
            // D-03 ULID PK — CHAR(26). Matches v2 milestone Phase 1 D-14 convention.
            $t->ulid('id')->primary();

            // Cascade FK to competitors (Phase 5 — already exists).
            $t->foreignId('competitor_id')
                ->constrained('competitors')
                ->cascadeOnDelete();

            $t->string('name', 255);

            // Protocol enum-like (string — the model exposes constants).
            $t->string('protocol', 8)->default('sftp');
            $t->string('host', 255);
            $t->unsignedSmallInteger('port')->default(22);
            $t->string('username', 255);

            // D-04 — encrypted Eloquent cast on these 3 columns at the model layer.
            $t->text('password_encrypted')->nullable();
            $t->longText('private_key_encrypted')->nullable();
            $t->text('passphrase_encrypted')->nullable();

            $t->string('base_path', 512)->default('/');
            $t->string('filename_pattern', 512)
                ->default('/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/');
            $t->string('cron_expression', 64)->default('*/15 * * * *');

            // Claude Discretion — security default ON; ops can disable per-source for self-signed certs.
            $t->boolean('verify_ssl')->default(true);

            $t->boolean('is_active')->default(true);

            // D-12 atomic circuit-breaker counter — `increment()` is a single UPDATE.
            $t->unsignedInteger('consecutive_failures')->default(0);

            $t->timestamp('last_pulled_at')->nullable();
            $t->string('last_pull_status', 16)->nullable();
            $t->unsignedInteger('last_pull_files_fetched')->default(0);
            $t->text('last_pull_error')->nullable();

            $t->timestamps();

            // One Cisco "weekly_csv" only.
            $t->unique(['competitor_id', 'name'], 'competitor_ftp_sources_competitor_name_unique');

            // Covered query for the every-15-min "next source to pull".
            $t->index(['is_active', 'last_pulled_at'], 'competitor_ftp_sources_active_lastpulled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_ftp_sources');
    }
};
