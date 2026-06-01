<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Widen integration_events.endpoint from VARCHAR(255) to TEXT.
 *
 * 2026-05-31 — products:source-images crashed mid-batch on a Samsung
 * image URL longer than 255 chars (query parameters in the image CDN URL
 * pushed total length to ~280 chars):
 *
 *   https://images.samsung.com/is/image/samsung/assets/test/support/
 *   mobile-devices/.../TF-FAQ7-2021-what-does-ipx8-rated-water-resistance
 *   -mean-for-my-galaxy_01-2_banner_mo.jpg?$720_N_JPG$
 *
 * MySQL truncation error 1406 killed the queue worker mid-job. Prod was
 * patched live with a direct `ALTER TABLE integration_events MODIFY
 * endpoint TEXT NULL` (see meetingstore-resume-state memory). This
 * migration replays the change through Laravel so `migrate:fresh` and
 * any future environment bootstrap match prod schema.
 *
 * TEXT holds up to 65 KB which is more than enough headroom for any
 * realistic image-CDN URL. We considered LONGTEXT but the only realistic
 * caller is image-fetch logging and image URLs almost never exceed 2 KB.
 *
 * Idempotent: skipping the modify when the column is already TEXT lets
 * us re-run the migration without erroring on prod where the ALTER
 * already happened. Detection uses information_schema.COLUMNS DATA_TYPE
 * which returns 'text' for TEXT and 'varchar' for VARCHAR.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite stores TEXT-like columns dynamically — there is no fixed
        // VARCHAR(255) constraint to widen. The original create migration
        // used $t->string() which on SQLite already maps to TEXT-ish under
        // the hood. So the migration is a no-op on SQLite (tests run on
        // :memory: SQLite). Production MySQL is the only target.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->isAlreadyText()) {
            return;
        }

        Schema::getConnection()->statement(
            'ALTER TABLE integration_events MODIFY endpoint TEXT NULL',
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Defensive: only narrow back if currently TEXT. Rolling back to
        // VARCHAR(255) on a prod row that exceeds 255 chars would silently
        // truncate, so this down() is best-effort.
        if (! $this->isAlreadyText()) {
            return;
        }

        Schema::getConnection()->statement(
            'ALTER TABLE integration_events MODIFY endpoint VARCHAR(255) NOT NULL',
        );
    }

    /**
     * MySQL-only — caller guards on getDriverName() === 'mysql' before invoking.
     */
    private function isAlreadyText(): bool
    {
        $row = Schema::getConnection()->selectOne(
            'SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['integration_events', 'endpoint'],
        );

        return $row !== null && strtolower((string) ($row->DATA_TYPE ?? '')) === 'text';
    }
};
