<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Plan 01 — quote_lines table (QUOT-02).
 *
 * Per-line snapshot of price + product context at quote creation. The
 * `_at_quote` suffix on price columns is load-bearing — these are NEVER
 * recomputed after quote creation (D-13 line snapshot immutability). Plan
 * 11-02's `QuoteLineImmutabilityObserver` enforces by throwing on `saving`
 * when status != draft AND price/snapshot is dirty.
 *
 * Phase 11 D-13 — VAT-INCLUSIVE storage convention:
 *   `unit_price_pence_at_quote` is stored VAT-INCLUSIVE (matches
 *   PriceCalculator::compute output). PDF strips VAT at render time via
 *   `PriceCalculator::stripVat()` inverse helper (Phase 3 D-05). NEVER
 *   store as float/decimal — Pitfall 1 (case email "1999" → 19.99 → 19
 *   pence drops 99% of value).
 *
 * Column dispositions:
 *   - id CHAR(26) ULID PK (stable references for Plan 11-04 Bitrix
 *     `crm.deal.productrows.set` line-item shape)
 *   - quote_id FK CHAR(26) ON DELETE CASCADE (orphan rows are meaningless)
 *   - sku VARCHAR(255) (matches products.sku — denormalised, no FK because
 *     Plan 11-03 D-10 tolerates manual SKU entry for SKUs not yet in
 *     products table — a Phase 6 auto-create gap)
 *   - quantity_int UNSIGNED INTEGER (D-12 — validation 1..9999 enforced
 *     in Filament form + Quote::saving observer in Plan 11-02)
 *   - unit_price_pence_at_quote UNSIGNED BIGINT — VAT-INCLUSIVE pence
 *   - line_total_pence_at_quote UNSIGNED BIGINT — unit * quantity (recalc
 *     on quantity edit while in draft; frozen after status=sent)
 *   - product_snapshot JSON — {name, brand, category, image_url} captured
 *     at line creation; survives subsequent product renames/recategorisation
 *   - sort_order UNSIGNED SMALLINT — preserves admin-ordered line sequence
 *     in Filament Repeater + PDF render
 *
 * Indexes:
 *   - sku — lookup for "show me all quotes for this product" admin query
 *   - (quote_id, sort_order) — natural ordering for Quote->lines() relation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $t->string('sku', 255);
            $t->unsignedInteger('quantity_int');
            $t->unsignedBigInteger('unit_price_pence_at_quote')->comment('VAT-INCLUSIVE pence — Phase 11 D-13 + Pitfall 1; NEVER cast as decimal/float');
            $t->unsignedBigInteger('line_total_pence_at_quote')->comment('VAT-INCLUSIVE pence = unit_price_pence_at_quote * quantity_int');
            $t->json('product_snapshot');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index('sku');
            $t->index(['quote_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
