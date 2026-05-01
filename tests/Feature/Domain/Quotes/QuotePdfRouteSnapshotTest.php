<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\QuoteLineWriter;
use App\Domain\Quotes\Services\QuotePdfRenderer;
use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|==============================================================================
| Phase 11 Plan 04 — QuotePdfRouteSnapshotTest (D-13 + Anti-Pattern 1 ship gate)
|==============================================================================
|
| Regression test that locks the PDF render path's read-only-from-snapshot
| invariant. If the renderer ever calls TradeRuleResolver again (Anti-Pattern 1
| in 11-RESEARCH), a downstream PricingRule edit would yield a different PDF
| even though the QuoteLine snapshot is unchanged. This test catches that.
|
| Test flow:
|   1. Create a Quote with 2 snapshotted lines via QuoteLineWriter (Plan 11-02
|      sole creation path).
|   2. Render PDF #1 → normalise out the dompdf timestamps (CreationDate,
|      ModDate, /ID, etc.) → sha256 the bytes.
|   3. Mutate the underlying PricingRule.margin_basis_points by +1000bps.
|   4. Render PDF #2 from quote->fresh('lines') → normalise → sha256.
|   5. Assert sha256(stripped #1) === sha256(stripped #2).
|
| Skip-on-MySQL-offline parity with the SHIP GATE test.
*/

uses(RefreshDatabase::class);

function skipIfMySqlOfflineRouteSnap(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineRouteSnap();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
    config(['laravel-pdf.driver' => 'dompdf']);
});

/**
 * Strip dompdf-injected timestamps + IDs from PDF bytes so the byte-identity
 * comparison ignores the per-render entropy and tests only the data-bearing
 * stream (text + layout). Without this normalisation, every render differs.
 */
function stripPdfMetadata(string $bytes): string
{
    // /CreationDate (D:20260501145200+00'00')
    $bytes = preg_replace('/\/CreationDate \(D:[^)]*\)/', '/CreationDate (D:NORM)', $bytes) ?? $bytes;
    // /ModDate (D:...)
    $bytes = preg_replace('/\/ModDate \(D:[^)]*\)/', '/ModDate (D:NORM)', $bytes) ?? $bytes;
    // /ID [<hex> <hex>]
    $bytes = preg_replace('/\/ID \[<[0-9a-fA-F]+> <[0-9a-fA-F]+>\]/', '/ID [<NORM> <NORM>]', $bytes) ?? $bytes;
    // The Blade footer also embeds now()->format('Y-m-d H:i') — second-resolution
    // can drift between renders. Strip to "NORM" for the test only.
    $bytes = preg_replace('/Generated \d{4}-\d{2}-\d{2} \d{2}:\d{2}/', 'Generated NORM', $bytes) ?? $bytes;

    return $bytes;
}

it('PHASE 11 — PDF render is byte-identical after PricingRule mutation (Anti-Pattern 1)', function (): void {
    skipIfMySqlOfflineRouteSnap();

    // ── Setup: customer group + 2 SKUs with brand pricing rules ──────────
    $group = CustomerGroup::factory()->create();
    $skus = ['SNAP-PDF-001', 'SNAP-PDF-002'];
    $brandIds = [9001, 9002];
    foreach ($skus as $i => $sku) {
        Product::factory()->create([
            'sku' => $sku,
            'buy_price' => 100.00,
            'brand_id' => $brandIds[$i],
        ]);
    }

    $rules = [];
    foreach ($brandIds as $i => $brandId) {
        $rules[$i] = PricingRule::factory()->create([
            'scope' => PricingRule::SCOPE_BRAND,
            'customer_group_id' => $group->id,
            'brand_id' => $brandId,
            'margin_basis_points' => 2000, // 20%
            'priority' => 200,
            'active' => true,
        ]);
    }

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'customer_email' => 'snapshot-pdf@example.com',
        'status' => Quote::STATUS_DRAFT,
    ]);

    $writer = app(QuoteLineWriter::class);
    $writer->add($quote, $skus[0], 2);
    $writer->add($quote, $skus[1], 3);

    $renderer = app(QuotePdfRenderer::class);

    // ── Step 1: render PDF before mutation ──────────────────────────────
    $beforeBase64 = $renderer->render($quote->fresh('lines'));
    $beforeBytes = (string) base64_decode($beforeBase64, true);
    $beforeNorm = stripPdfMetadata($beforeBytes);
    $beforeHash = hash('sha256', $beforeNorm);

    // ── Step 2: mutate rules — margin +1000bps each ──────────────────────
    foreach ($rules as $rule) {
        $rule->margin_basis_points += 1000;
        $rule->save();
    }

    // ── Step 3: re-render PDF after mutation ─────────────────────────────
    $afterBase64 = $renderer->render($quote->fresh('lines'));
    $afterBytes = (string) base64_decode($afterBase64, true);
    $afterNorm = stripPdfMetadata($afterBytes);
    $afterHash = hash('sha256', $afterNorm);

    // ── Step 4: assert byte-identical ────────────────────────────────────
    expect($afterHash)->toBe(
        $beforeHash,
        'PHASE 11 SHIP GATE FAILED — PDF bytes drifted after PricingRule mutation. '
        .'QuotePdfRenderer or quote.blade.php is calling TradeRuleResolver instead '
        .'of reading QuoteLine snapshot columns (Anti-Pattern 1 violation).',
    );
});
