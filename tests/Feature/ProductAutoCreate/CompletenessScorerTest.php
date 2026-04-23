<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\ProductAutoCreate\Services\CompletenessScorer;

it('returns full 100 score for a fully-populated auto-create product', function (): void {
    $longDescription = '<h2>Overview</h2><p>X</p>'
        .'<h2>Key Features</h2><ul><li>A</li></ul>'
        .'<h2>Technical Specifications</h2><table></table>'
        ."<h2>What's in the Box</h2><ul><li>Y</li></ul>";

    $product = Product::factory()->create([
        'name' => 'Logitech MeetUp Video Conferencing',
        'slug' => 'logitech-meetup-video-conferencing-unique',
        'meta_description' => str_repeat('a', 120),      // in 100-160 band
        'short_description' => '<ul><li>A</li><li>B</li><li>C</li></ul>',
        'long_description' => $longDescription,
        'brand_id' => 5,
        'category_id' => 7,
        'image_url' => 'https://cdn.example/product.webp',
        'sell_price' => 199.99,
    ]);

    $scorer = app(CompletenessScorer::class);
    $result = $scorer->score($product);

    expect($result['score'])->toBe(100);
    expect($result['missing_fields'])->toBe([]);
    expect($result['ready_to_publish'])->toBeTrue();
});

it('penalises each missing field with its weight', function (): void {
    // Empty Product — every field band should fail.
    $product = Product::factory()->create([
        'name' => '',
        'slug' => null,
        'meta_description' => null,
        'short_description' => null,
        'long_description' => null,
        'brand_id' => null,
        'category_id' => null,
        'image_url' => null,
        'sell_price' => 0,
    ]);

    $scorer = app(CompletenessScorer::class);
    $result = $scorer->score($product);

    expect($result['score'])->toBe(0);
    expect($result['ready_to_publish'])->toBeFalse();
    expect($result['missing_fields'])->toContain('title')
        ->and($result['missing_fields'])->toContain('slug')
        ->and($result['missing_fields'])->toContain('meta_description')
        ->and($result['missing_fields'])->toContain('short_description')
        ->and($result['missing_fields'])->toContain('long_description')
        ->and($result['missing_fields'])->toContain('brand_id')
        ->and($result['missing_fields'])->toContain('category_id')
        ->and($result['missing_fields'])->toContain('image')
        ->and($result['missing_fields'])->toContain('price');
});

it('marks image as missing when image_url equals the placeholder URL (D-07)', function (): void {
    $placeholder = rtrim((string) env('APP_URL', 'http://localhost'), '/')
        .'/images/av-product-placeholder.webp';
    config(['product_auto_create.placeholder_image_url' => $placeholder]);

    $product = Product::factory()->create([
        'image_url' => $placeholder,
    ]);

    $scorer = app(CompletenessScorer::class);
    $result = $scorer->score($product);

    expect($result['missing_fields'])->toContain('image');
});

it('ready_to_publish uses config threshold', function (): void {
    config(['product_auto_create.completeness_publish_threshold' => 100]);

    $product = Product::factory()->create([
        'name' => 'Good name',
        'slug' => 'good-name-unique',
    ]);

    $scorer = app(CompletenessScorer::class);
    $result = $scorer->score($product);

    expect($result['score'])->toBeLessThan(100);
    expect($result['ready_to_publish'])->toBeFalse();
});
