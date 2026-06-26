<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260626-q2b — capture the REAL supplier feed date locally.
 *
 * Until now the Suppliers admin page derived "Feed date" from
 * supplier_offer_snapshots.recorded_at — which is the date MeetingStore last
 * PULLED, stamped today() on every supplier:db-sync run regardless of whether
 * the supplier actually refreshed. PROVEN on prod: Nuvias showed fresh/today,
 * but its real file date is 2026-05-14 00:20:24.
 *
 * The supplier's TRUE file date lives on the REMOTE feeds table as
 * feeds.remote_date (live probe 2026-06-26 — Nuvias feeds.id=1,
 * remote_date='2026-05-14 00:20:24', cron_run='2026-05-20 12:53:12', status=0).
 * MS never read it. This migration adds the three feed-metadata columns the new
 * suppliers:sync-feed-dates command upserts:
 *
 *   - feed_remote_date : feeds.remote_date — the actual supplier file date.
 *   - feed_cron_run    : feeds.cron_run — when the upstream puller last ran.
 *   - feed_status      : feeds.status — 0 = feed disabled upstream (Nuvias).
 *
 * All nullable — a supplier with no remote feed row (or a zero-date) stays NULL.
 * Index on feed_remote_date so the Suppliers page can sort by the real date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dateTime('feed_remote_date')->nullable();
            $table->dateTime('feed_cron_run')->nullable();
            $table->integer('feed_status')->nullable();

            $table->index('feed_remote_date');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Drop the index before the column it covers (some drivers require it).
            $table->dropIndex(['feed_remote_date']);
            $table->dropColumn(['feed_remote_date', 'feed_cron_run', 'feed_status']);
        });
    }
};
