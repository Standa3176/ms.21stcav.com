<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — Competitors master table (COMP-01..COMP-12 foundation).
 *
 * Ops-managed list of competitors MeetingStore tracks. Seeded empty per D-02;
 * n8n drops CSVs named `{slug}_{YYYY-MM-DD}.csv` (D-01) — the watcher resolves
 * `competitor_id` by looking up `slug`. First sighting of a new prefix creates
 * a `status=pending` row which ops then promotes via Filament.
 *
 * - `slug` UNIQUE (64) — the filename-prefix key; lowercase, underscore/hyphen.
 * - `status` enum(pending|active|inactive) indexed for stale-feed queries.
 * - `is_active` separate toggle so ops can pause ingest without losing history.
 * - `last_ingest_at` stamped by IngestCompetitorCsvJob (Plan 05-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('name');
            $t->string('website_url')->nullable();
            $t->text('map_policy_notes')->nullable();
            $t->enum('status', ['pending', 'active', 'inactive'])->default('pending')->index();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_ingest_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
