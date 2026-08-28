<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| 260828-plk — does the catalogue describe REAL products?
|--------------------------------------------------------------------------
|
| Pricing has had a daily health check since 260825-n5v. Identity has had
| nothing, which is why ~2,242 fabricated barcodes lived on the storefront for
| months and were found only because someone went looking on 2026-08-27.
|
| The load-bearing tests here are the NEGATIVES. A report that flags real
| products is one people stop reading by the second week, and then the genuine
| fault scrolls past unnoticed — so every detector below is paired with a case
| that must NOT fire.
*/

function identityProduct(array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'status' => 'publish',
        'buy_price' => 100.00,
        'sell_price' => 150.00,
        'gallery_image_urls' => ['a.webp', 'b.webp', 'c.webp'],
    ], $attributes));
}

// ── barcodes ──────────────────────────────────────────────────────────────

it('catches a barcode that is really the SKU with its letters stripped out', function (): void {
    // The signature fault: 61U3010000AC held "613010000". Every Philips
    // xxBDLxxxx/00 and every AVer 61Uxxxxxxx in the catalogue looked like this.
    identityProduct(['sku' => '61U3010000AC', 'ean' => '613010000']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->expectsOutputToContain('SKU-DERIVED BARCODE')
        ->assertExitCode(1);
});

it('catches a GS1 company prefix padded out with zeros', function (): void {
    // DS-D6055UN-D/S held 6931850000000 — Hikvision's prefix, no product code.
    identityProduct(['sku' => 'DS-D6055UN-D/S', 'ean' => '6931850000000']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->expectsOutputToContain('PLACEHOLDER BARCODE')
        ->assertExitCode(1);
});

it('catches a padded prefix whose check digit is not zero', function (): void {
    // Vision VFM-W4X4 arrived as 4934292000003 on the day this command shipped:
    // prefix + five zeros + check digit 3. The first rule required six trailing
    // zeros and so only caught placeholders whose check digit was 0 by luck.
    identityProduct(['sku' => 'VFM-W4X4', 'ean' => '4934292000003']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->expectsOutputToContain('PLACEHOLDER BARCODE')
        ->assertExitCode(1);
});

it('does not mistake a real barcode ending in zeros for a padded prefix', function (): void {
    // The widened rule must not start eating genuine GTINs. A real product code
    // occupies the positions a placeholder leaves as zeros.
    identityProduct(['sku' => 'REAL-1', 'ean' => '5099206131255']);
    identityProduct(['sku' => 'REAL-2', 'ean' => '0698833028492']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);
});

it('catches one barcode shared by several products', function (): void {
    // Three Hikvision products shared 6936420000000. One GTIN on many products
    // is a feed-integrity problem, not three small errors.
    identityProduct(['sku' => 'DS-D5C75RB/A2L', 'ean' => '5099206131255']);
    identityProduct(['sku' => 'DS-D5C75RB/B2L', 'ean' => '5099206131255']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->expectsOutputToContain('DUPLICATE BARCODE')
        ->assertExitCode(1);
});

it('catches a barcode whose check digit fails', function (): void {
    identityProduct(['sku' => 'THING-1', 'ean' => '5099206131250']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->expectsOutputToContain('INVALID BARCODE')
        ->assertExitCode(1);
});

it('passes a valid 13-digit GTIN', function (): void {
    identityProduct(['sku' => '960-001699', 'ean' => '5099206131255']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);
});

it('passes a genuine 12-digit UPC-A without demanding 13 digits', function (): void {
    // DU7099Z-BK and PT12X-LINK-4K-GY are stored unpadded. Google accepts
    // UPC-A, so flagging them would be a false alarm on correct data.
    identityProduct(['sku' => 'DU7099Z-BK', 'ean' => '813097025876']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);
});

it('does not call a VALID barcode sku-derived just because the digits line up', function (): void {
    // A real GTIN must never be reported as fabricated. The derived check only
    // fires when the value ALSO fails its check digit.
    identityProduct(['sku' => 'SKU-5099206131255', 'ean' => '5099206131255']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);
});

it('ignores products with no barcode at all', function (): void {
    // Empty is the correct state for a product with no manufacturer GTIN. It
    // is the state the 2,219-row clear list is moving products INTO.
    identityProduct(['sku' => 'NO-EAN', 'ean' => null]);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);
});

// ── names ─────────────────────────────────────────────────────────────────

it('catches an unresolved token left in the title', function (): void {
    // MB65PRO-A02 reads "Yealink — nan 4k conference camera".
    identityProduct(['sku' => 'MB65PRO-A02', 'name' => 'Yealink nan 4k conference camera']);

    $this->artisan('products:identity-health-check --section=name')
        ->expectsOutputToContain('UNRESOLVED TOKEN');
});

it('does not flag a real word that merely contains a token', function (): void {
    // "Nano" contains "nan". Whole-word matching or this fires constantly.
    identityProduct(['sku' => 'NANO-1', 'name' => 'Barco ClickShare Nano Wireless Presentation']);

    $this->artisan('products:identity-health-check --section=name')
        ->doesntExpectOutputToContain('UNRESOLVED TOKEN');
});

it('catches a name that identifies nothing', function (): void {
    // "AVer 60V2B10000AL Accessory" — brand, part number, category noun. This
    // one was held back from publishing on 2026-08-27 for exactly this reason.
    identityProduct(['sku' => '60V2B10000AL', 'name' => 'AVer 60V2B10000AL Accessory']);

    $this->artisan('products:identity-health-check --section=name')
        ->expectsOutputToContain('PLACEHOLDER NAME');
});

it('does not flag a terse but genuine product name', function (): void {
    // The detector must survive short real names or it is useless.
    identityProduct(['sku' => 'VFM-DSXP', 'name' => 'Vision VFM-DSXP Desktop LCD Display Stand']);

    $this->artisan('products:identity-health-check --section=name')
        ->doesntExpectOutputToContain('PLACEHOLDER NAME');
});

// ── images ────────────────────────────────────────────────────────────────

it('reports a product with no images', function (): void {
    identityProduct(['sku' => 'MFCUB', 'gallery_image_urls' => []]);

    $this->artisan('products:identity-health-check --section=image')
        ->expectsOutputToContain('NO IMAGE');
});

it('reports a product carrying only one image', function (): void {
    // Eight of the 50 published on 2026-08-27 went live with a single image,
    // all casualties of manufacturer CDNs blocking the fetcher.
    identityProduct(['sku' => 'JVCU360-N', 'gallery_image_urls' => ['only.webp']]);

    $this->artisan('products:identity-health-check --section=image')
        ->expectsOutputToContain('SINGLE IMAGE');
});

// ── severity ──────────────────────────────────────────────────────────────

it('fails the run for a barcode fault but not for a thin gallery', function (): void {
    // A wrong GTIN is a Shopping disapproval; a thin gallery is a backlog. An
    // alarm that fires every morning for a known backlog gets muted, and then
    // the real one is missed too.
    identityProduct(['sku' => 'THIN', 'gallery_image_urls' => ['one.webp'], 'ean' => null]);
    identityProduct(['sku' => 'NONE', 'gallery_image_urls' => [], 'ean' => null]);

    $this->artisan('products:identity-health-check')
        ->assertExitCode(0);
});

it('leaves unpublished products alone unless asked', function (): void {
    // Drafts are work in progress by definition — reporting them would bury
    // the storefront faults that actually cost money.
    identityProduct(['sku' => 'DRAFT-1', 'status' => 'draft', 'ean' => '613010000']);

    $this->artisan('products:identity-health-check --section=barcode')
        ->assertExitCode(0);

    $this->artisan('products:identity-health-check --section=barcode --include-unpublished')
        ->assertExitCode(1);
});
