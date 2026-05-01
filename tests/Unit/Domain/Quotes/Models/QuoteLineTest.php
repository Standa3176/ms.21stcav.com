<?php

declare(strict_types=1);

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 01 Task 2 — QuoteLine model behaviour tests.
|--------------------------------------------------------------------------
|
| Integer pence casts (Pitfall 1), product_snapshot array cast, HasUlids,
| BelongsTo Quote relation. Plan 11-02 ships the immutability observer +
| QuotePdfPriceImmunityTest regression test.
*/

it('QuoteLine PK is a 26-character ULID via HasUlids trait', function (): void {
    $line = QuoteLine::factory()->create();
    expect($line->id)->toBeString()->toHaveLength(26);
});

it('QuoteLine casts unit_price_pence_at_quote as integer (Pitfall 1)', function (): void {
    $line = QuoteLine::factory()->create([
        'unit_price_pence_at_quote' => 1999,
        'line_total_pence_at_quote' => 5997,
        'quantity_int' => 3,
    ]);
    expect($line->unit_price_pence_at_quote)->toBeInt()->toBe(1999);
    expect($line->line_total_pence_at_quote)->toBeInt()->toBe(5997);
    expect($line->quantity_int)->toBeInt()->toBe(3);
});

it('QuoteLine casts product_snapshot as array', function (): void {
    $snapshot = [
        'name' => 'Crestron HDMI Switcher',
        'brand' => 'Crestron',
        'category' => 'AV',
        'image_url' => null,
    ];
    $line = QuoteLine::factory()->create(['product_snapshot' => $snapshot]);

    expect($line->product_snapshot)->toBeArray();
    expect($line->product_snapshot['name'])->toBe('Crestron HDMI Switcher');
    expect($line->product_snapshot['brand'])->toBe('Crestron');
});

it('QuoteLine->quote() returns BelongsTo Quote', function (): void {
    $quote = Quote::factory()->create();
    $line = QuoteLine::factory()->create(['quote_id' => $quote->id]);

    expect($line->quote)->not->toBeNull();
    expect($line->quote->id)->toBe($quote->id);
    expect($line->quote)->toBeInstanceOf(Quote::class);
});

it('QuoteLine fillable includes price columns (PriceSnapshotter is the legitimate writer in Plan 11-02)', function (): void {
    // The fillable list INCLUDES unit_price_pence_at_quote because
    // Plan 11-02 PriceSnapshotter mass-assigns at creation. The
    // immutability observer (Plan 11-02) catches direct mutations
    // after creation — not the fillable list. This test simply pins
    // the fillable shape so a future refactor can't silently drop
    // the snapshot writer's mass-assignment path.
    $fillable = (new QuoteLine)->getFillable();
    expect($fillable)->toContain('unit_price_pence_at_quote');
    expect($fillable)->toContain('line_total_pence_at_quote');
    expect($fillable)->toContain('product_snapshot');
    expect($fillable)->toContain('sort_order');
    expect($fillable)->toContain('quote_id');
    expect($fillable)->toContain('sku');
    expect($fillable)->toContain('quantity_int');
});

it('QuoteLine deletes via CASCADE when parent Quote is deleted', function (): void {
    $quote = Quote::factory()->create();
    $line = QuoteLine::factory()->create(['quote_id' => $quote->id]);
    $lineId = $line->id;

    $quote->delete();

    expect(QuoteLine::find($lineId))->toBeNull();
});
