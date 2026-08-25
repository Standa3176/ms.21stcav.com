<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260825-h2r — competitor rows that must not price a given SKU.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE CASE THAT CREATED IT — SKU homonyms
 *
 * `CP4` is two different products:
 *   ours          Unicol / AVM ceiling mount, cost GBP 24.96 (Unicol) /
 *                 GBP 30.28 (Northamber)
 *   AVITDirect's  Crestron CP4 control processor, ~GBP 1,748
 *
 * Both feeds are CORRECT about their own product. Nothing is anomalous, no
 * price is wrong, and no supplier has misbehaved — the string simply collides.
 *
 * Before the 2026-08-09 margin ceiling existed, the undercut logic matched them
 * and priced our GBP 25 mount at GBP 1,517.99 — exactly AVITDirect's GBP 1,518.00
 * less the 1p `beat_by_pennies`. The ceiling has blocked every attempt since
 * (5,737% margin), so the guard works; this table stops the match arising.
 *
 * WHY NOT is_price_anomaly: that flag means "the feed's price is WRONG".
 * AVITDirect's price is right. Overloading it would corrupt the meaning of
 * every anomaly reading afterwards, and the next person auditing anomalies
 * would find a row that is not one. A mismatch is a different fact and gets a
 * different record.
 *
 * Schema notes:
 * - `competitor_id` NULLABLE — null means "every competitor", for a string so
 *   generic no competitor's row should ever price it. A specific id is the
 *   normal case and the narrower claim, so it is preferred.
 * - `match_key` is stored NORMALISED (lower + trim), mirroring how the pricing
 *   lookup compares sku/mpn, so an exclusion cannot miss on casing alone.
 * - `reason` is NOT NULL: an exclusion silently removes a competitor from
 *   pricing decisions forever. A future reader must be able to tell "CP4 is a
 *   Crestron at AVITDirect, a mount here" from someone's undocumented hunch.
 * - UNIQUE(competitor_id, match_key) — re-running an add is idempotent rather
 *   than piling up duplicates. NOTE: MySQL treats NULLs as distinct in a unique
 *   index, so the all-competitors variant is not protected by it; the command
 *   guards that case explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_match_exclusions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('competitor_id')
                ->nullable()
                ->constrained('competitors')
                ->cascadeOnDelete();
            $t->string('match_key', 128);
            $t->text('reason');
            $t->timestamps();

            $t->unique(['competitor_id', 'match_key'], 'uniq_competitor_match_key');
            $t->index('match_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_match_exclusions');
    }
};
