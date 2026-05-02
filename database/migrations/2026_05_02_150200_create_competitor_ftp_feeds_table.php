<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.2 Plan 01 — D-01 + D-02: per-remote-file feed table.
 *
 * One row per file inside the shared FTP folder. Auto-increment integer PK
 * (D-02) so admin-managed rows have stable per-row identity in the Filament
 * table (matches screenshot's `1, 10, 12, 13, 16, 18, 27...`).
 *
 * SoftDeletes for audit history (CONTEXT.md Claude's Discretion).
 *
 * UNIQUE local_filename — collision check at create time prevents two feeds
 * overwriting the same file in storage/app/competitors/incoming/.
 *
 * FK behaviour:
 *   - competitor_id — cascadeOnDelete (deleting a competitor removes its feeds)
 *   - credential_id — restrictOnDelete (cannot drop a credential with active feeds)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_ftp_feeds', function (Blueprint $t): void {
            // D-02 — auto-increment integer PK matching screenshot IDs.
            $t->id();

            $t->foreignId('competitor_id')
                ->constrained('competitors')
                ->cascadeOnDelete();

            // D-01 — ULID FK to credentials (restrict; can't delete cred with feeds).
            $t->foreignUlid('credential_id')
                ->constrained('competitor_ftp_credentials')
                ->restrictOnDelete();

            $t->string('remote_filename', 512); // exact filename (e.g. 'PRICE.ZIP')
            $t->string('local_filename', 255)->unique(); // canonical .csv name in incoming/
            $t->string('format', 8); // 'csv' | 'tsv' | 'zip' | 'txt' (D-05)

            $t->boolean('is_active')->default(true);

            $t->timestamp('last_pulled_at')->nullable();   // local fetch time
            $t->timestamp('remote_file_date')->nullable(); // remote mtime — drives stale-feed alert
            $t->string('last_pull_status', 16)->nullable(); // success|failed|skipped|no_change
            $t->text('last_pull_error')->nullable();
            $t->unsignedInteger('consecutive_failures')->default(0); // D-01

            $t->softDeletes(); // CONTEXT — feeds soft-delete for audit history

            $t->timestamps();

            // Composite index — scheduled pull query "active feeds first, integer PK ordering".
            $t->index(['is_active', 'id'], 'competitor_ftp_feeds_active_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_ftp_feeds');
    }
};
