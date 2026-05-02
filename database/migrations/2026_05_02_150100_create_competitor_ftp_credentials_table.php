<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.2 Plan 01 — D-01 + D-03: shared FTP credentials table.
 *
 * Typically 1 row representing the supplier-aggregated FTP folder. ULID PK
 * (Phase 1 D-14 ULID convention). 3 encrypted credential columns
 * (D-03 — `'encrypted'` Eloquent cast applies AES-256 at the model layer).
 *
 * No SoftDeletes — credentials hard-delete (CONTEXT decision); FK from
 * competitor_ftp_feeds.credential_id is restrictOnDelete so rows with
 * dependent feeds cannot be removed at the DB layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_ftp_credentials', function (Blueprint $t): void {
            // D-01 — ULID PK (consistent with Phase 1 D-14 + Phase 11.1 precedent).
            $t->ulid('id')->primary();

            $t->string('name', 255)->unique(); // e.g. 'supplier_aggregate'
            $t->string('protocol', 8)->default('sftp');
            $t->string('host', 255);
            $t->unsignedSmallInteger('port')->default(22);
            $t->string('username', 255);

            // D-03 — encrypted Eloquent cast on these 3 columns at the model layer.
            $t->text('password_encrypted')->nullable();
            $t->longText('private_key_encrypted')->nullable();
            $t->text('passphrase_encrypted')->nullable();

            $t->string('base_path', 512)->default('/');
            $t->boolean('verify_ssl')->default(true);
            $t->boolean('is_active')->default(true);

            // 'Test connection' Filament action populates these.
            $t->timestamp('last_test_at')->nullable();
            $t->string('last_test_status', 16)->nullable(); // 'ok' | 'failed'
            $t->text('last_test_error')->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_ftp_credentials');
    }
};
