<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\ProductAutoCreate\Services\ProductSlugGenerator;
// Pest.php already registers RefreshDatabase on tests/Feature — no extra uses() needed.

it('returns base slug when no collision exists (D-05 step 1)', function (): void {
    $gen = app(ProductSlugGenerator::class);
    $slug = $gen->generate('Logitech MeetUp', 'SIMPLE-001');

    expect($slug)->toBe('logitech-meetup');
});

it('appends -{sku} on first collision (D-05 step 2)', function (): void {
    Product::factory()->create(['slug' => 'logitech-meetup']);

    $gen = app(ProductSlugGenerator::class);
    $slug = $gen->generate('Logitech MeetUp', 'SKU-XYZ');

    expect($slug)->toBe('logitech-meetup-sku-xyz');
});

it('appends -{productId} on second collision (D-05 step 3)', function (): void {
    Product::factory()->create(['slug' => 'logitech-meetup']);
    Product::factory()->create(['slug' => 'logitech-meetup-sku-abc']);

    $gen = app(ProductSlugGenerator::class);
    $slug = $gen->generate('Logitech MeetUp', 'SKU-ABC', productId: 999);

    expect($slug)->toBe('logitech-meetup-999');
});

it('ignores the excluded product id when checking existence', function (): void {
    $existing = Product::factory()->create(['slug' => 'logitech-meetup']);

    $gen = app(ProductSlugGenerator::class);
    // Regenerating the slug for THAT SAME product should not trip on its own row.
    $slug = $gen->generate('Logitech MeetUp', 'ANY-SKU', productId: $existing->id);

    expect($slug)->toBe('logitech-meetup');
});

it('falls back to product-{sku} when title slugifies to empty', function (): void {
    $gen = app(ProductSlugGenerator::class);
    // Unicode-only title that Str::slug drops to empty.
    $slug = $gen->generate('!!!', 'SKU-X');

    expect($slug)->toStartWith('product-');
});
