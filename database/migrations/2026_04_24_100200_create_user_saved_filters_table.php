<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Plan 01 — D-07 per-user saved Filament table filters.
 *
 * One row per (user, Resource slug, filter name) — allows any Filament Resource
 * table to offer a "Save current filter" dropdown backed by this table. Per-user
 * private for v1; cross-user sharing deferred (CONTEXT.md §deferred).
 *
 * Schema:
 *   - user_id              FK users.id — cascadeOnDelete (filter dies with user)
 *   - resource_slug        varchar(64) — Filament Resource slug
 *                          (e.g. 'products', 'crm-push-logs', 'suggestions')
 *   - filter_name          varchar(128) — user-facing label
 *                          (e.g. 'Pending last 7d', 'Brand=LG draft-only')
 *   - filter_payload_json  JSON — Filament filter state array
 *
 * Threat model (T-07-01-02): filter_payload_json is untrusted user-authored
 * state; Plan 07-03 validates the payload against the Resource's declared
 * filter schema before applying it.
 *
 * Indexes:
 *   - Unique (user_id, resource_slug, filter_name) — stops duplicate names
 *     per user per resource.
 *   - Secondary index (user_id, resource_slug) — the common list query
 *     ("my saved filters for this Resource").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_slug', 64);
            $table->string('filter_name', 128);
            $table->json('filter_payload_json');
            $table->timestamps();

            $table->unique(['user_id', 'resource_slug', 'filter_name']);
            $table->index(['user_id', 'resource_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_saved_filters');
    }
};
