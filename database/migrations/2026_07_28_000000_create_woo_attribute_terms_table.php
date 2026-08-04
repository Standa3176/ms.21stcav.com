<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 260728-fwx T1 — local cache of every global `pa_*` attribute's CURRENT term
 * vocabulary.
 *
 * Populated READ-ONLY from Woo by `spec:sync-taxonomy-cache` (nightly). The
 * upcoming SpecTaxonomyResolver (260728-fwx T2) reads THIS table (never Woo)
 * to resolve a raw spec value to an EXISTING term id — so a new product never
 * causes Woo to auto-create a duplicate term and re-pollute the cleaned facets.
 *
 * Driver-portable (SQLite in tests / MariaDB in prod): only string / integer /
 * timestamp column types + a composite unique + a plain index — no engine-
 * specific column features.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_attribute_terms', function (Blueprint $table): void {
            $table->id();
            $table->integer('attribute_id')->index();
            $table->string('attribute_slug', 191);
            $table->string('attribute_name', 191)->nullable();
            $table->integer('term_id');
            $table->string('term_name', 191);
            $table->string('term_slug', 191)->nullable();
            $table->timestamps();

            // One cached row per (attribute, term) — makes the sync idempotent
            // (updateOrCreate on this pair) and blocks accidental duplicates.
            $table->unique(['attribute_id', 'term_id'], 'woo_attribute_terms_attr_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_attribute_terms');
    }
};
