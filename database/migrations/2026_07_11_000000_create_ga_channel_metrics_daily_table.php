<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 Plan 15a-02 — GA4 channel/campaign daily snapshot table.
 *
 * ga_channel_metrics_daily mirrors one GA4 report: channel/campaign daily
 * performance. One row per grain =
 *   date × sessionDefaultChannelGroup × sessionSourceMedium × sessionCampaignName
 * so `google:pull-ga4` can re-pull a day idempotently (updateOrCreate on the
 * grain unique key overwrites without dupes).
 *
 * READ-ONLY ingestion: this table is written ONLY by the scheduled pull and
 * read by a Filament viewer — the app never writes GA4/Google-Ads back.
 *
 * Money convention: purchase_revenue is stored as integer PENNIES
 * (purchase_revenue_pennies = (int) round(purchase_revenue * 100)) — the app's
 * one money-mapping. unsigned bigint headroom for high-value B2B revenue.
 *
 * Driver-portable (SQLite tests / MariaDB prod — memory sqlite-mariadb-strict-
 * trap): plain Blueprint column types + a named composite unique + a date index.
 * source_medium / campaign kept at 191 chars so the composite unique stays
 * within MariaDB's utf8mb4 index-length ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_channel_metrics_daily', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('channel_group', 128);
            $t->string('source_medium', 191);
            $t->string('campaign', 191)->nullable();
            $t->unsignedInteger('sessions')->default(0);
            $t->unsignedInteger('key_events')->default(0);
            $t->unsignedInteger('transactions')->default(0);
            $t->unsignedBigInteger('purchase_revenue_pennies')->default(0);
            $t->timestamp('pulled_at')->nullable();

            // One row per grain — re-pulling a day overwrites via updateOrCreate.
            $t->unique(['date', 'channel_group', 'source_medium', 'campaign'], 'gcm_grain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_channel_metrics_daily');
    }
};
