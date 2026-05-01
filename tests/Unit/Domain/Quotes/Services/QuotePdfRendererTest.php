<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Quotes\Services\QuotePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 04 — QuotePdfRendererTest (QUOT-04 unit gate)
|--------------------------------------------------------------------------
|
| Locks behaviours of the PDF render layer:
|
|   1. ::render() returns base64-encoded PDF bytes — decodes to a stream
|      starting with '%PDF-' (DOMPDF output marker).
|
|   2. The decoded PDF text contains the customer_email, ulid_short_8,
|      'Subtotal', 'VAT 20%' and 'Total' literal strings — proves the
|      Blade template surfaced D-11 ex-VAT itemised convention correctly.
|
|   3. ex-VAT line amounts use PriceCalculator::stripVat — assert the
|      decoded PDF text contains the stripVat(unit_inc_vat, 2000) value
|      formatted as £X.XX so a future regression that prints unit_inc_vat
|      directly trips this assertion.
|
| Skip-on-MySQL-offline parity with Phase 11 Plan 02 (PriceSnapshotterTest).
*/

function skipIfMySqlOfflineQuotePdfRenderer(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineQuotePdfRenderer();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
    config(['laravel-pdf.driver' => 'dompdf']);
});

it('renders base64-encoded PDF bytes that decode to a valid PDF stream', function (): void {
    skipIfMySqlOfflineQuotePdfRenderer();

    $quote = Quote::factory()->create([
        'customer_email' => 'pdf-test@example.com',
        'customer_name' => 'PDF Test Customer',
        'total_pence_at_quote' => 12_000, // £100 ex VAT + 20% VAT = £120 inc
    ]);

    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'TEST-SKU-001',
        'quantity_int' => 2,
        'unit_price_pence_at_quote' => 6_000, // £50 inc VAT (£41.67 ex VAT after stripVat)
        'line_total_pence_at_quote' => 12_000,
        'product_snapshot' => ['name' => 'Test Product One'],
        'sort_order' => 10,
    ]);

    $renderer = app(QuotePdfRenderer::class);
    $base64 = $renderer->render($quote->fresh('lines'));

    expect($base64)->toBeString()->not->toBeEmpty();
    $bytes = base64_decode($base64, true);
    expect($bytes)->not->toBeFalse();
    expect(substr((string) $bytes, 0, 5))->toBe('%PDF-');
});

it('contains customer_email + ulid_short + Subtotal/VAT/Total literals in PDF text', function (): void {
    skipIfMySqlOfflineQuotePdfRenderer();

    $quote = Quote::factory()->create([
        'customer_email' => 'literals-check@example.com',
        'customer_name' => 'Literals Check',
        'total_pence_at_quote' => 24_000, // £200 inc VAT
    ]);

    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'LIT-001',
        'quantity_int' => 1,
        'unit_price_pence_at_quote' => 24_000,
        'line_total_pence_at_quote' => 24_000,
        'product_snapshot' => ['name' => 'Literals Product'],
        'sort_order' => 10,
    ]);

    $renderer = app(QuotePdfRenderer::class);
    $bytes = base64_decode($renderer->render($quote->fresh('lines')), true);
    expect($bytes)->not->toBeFalse();
    $text = (string) $bytes;

    // DOMPDF embeds literal text into the PDF stream — strpos works for ASCII
    // strings even though the PDF is binary. UTF-8 escapes for £ are fine.
    expect(str_contains($text, 'literals-check@example.com'))->toBeTrue('customer_email missing');
    expect(str_contains($text, $quote->ulidShort()))->toBeTrue('ulid_short missing');
    expect(str_contains($text, 'Subtotal'))->toBeTrue('Subtotal label missing');
    expect(str_contains($text, 'VAT 20%'))->toBeTrue('VAT 20% label missing');
    expect(str_contains($text, 'Total'))->toBeTrue('Total label missing');
});

it('renders ex-VAT line amounts via PriceCalculator::stripVat (D-11)', function (): void {
    skipIfMySqlOfflineQuotePdfRenderer();

    $calc = new PriceCalculator();

    $quote = Quote::factory()->create([
        'customer_email' => 'exvat@example.com',
        'total_pence_at_quote' => 12_000, // £100 ex / £120 inc
    ]);

    // unit £50 inc VAT → £41.67 ex VAT (round half-up of 5000*10000/12000=4166.67)
    $unitIncVat = 6_000;
    QuoteLine::factory()->create([
        'quote_id' => $quote->id,
        'sku' => 'EXVAT-001',
        'quantity_int' => 2,
        'unit_price_pence_at_quote' => $unitIncVat,
        'line_total_pence_at_quote' => 12_000,
        'product_snapshot' => ['name' => 'ExVat Product'],
        'sort_order' => 10,
    ]);

    $expectedUnitExVat = $calc->stripVat($unitIncVat); // 5000 (£50.00) for 6000 inc — actually 6000*10000/12000=5000
    $expectedFormatted = '£'.number_format($expectedUnitExVat / 100, 2, '.', ',');

    $renderer = app(QuotePdfRenderer::class);
    $bytes = base64_decode($renderer->render($quote->fresh('lines')), true);
    expect($bytes)->not->toBeFalse();
    $text = (string) $bytes;

    // The blade renders this exact formatted £-amount in the line table.
    expect(str_contains($text, $expectedFormatted))
        ->toBeTrue("Expected ex-VAT amount {$expectedFormatted} not found in PDF text");
});
