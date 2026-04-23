<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ImagePayloadBuilder;
use App\Domain\Products\Models\Product;

/**
 * Phase 6 Plan 02 Task 2 — ImagePayloadBuilder unit tests.
 *
 * Pure class — no DB writes. Uses `new Product()` with forceFill to avoid
 * the RefreshDatabase dependency for these Unit tests.
 */
it('returns empty images array when publicImageUrl is null', function (): void {
    $product = (new Product())->forceFill([
        'slug' => 'logitech-meetup',
        'name' => 'Logitech MeetUp',
    ]);

    $builder = new ImagePayloadBuilder();
    $payload = $builder->build($product, null);

    expect($payload)->toBe(['images' => []]);
});

it('returns empty images array when publicImageUrl is an empty string', function (): void {
    $product = (new Product())->forceFill([
        'slug' => 'logitech-meetup',
        'name' => 'Logitech MeetUp',
    ]);

    $builder = new ImagePayloadBuilder();
    $payload = $builder->build($product, '');

    expect($payload)->toBe(['images' => []]);
});

it('builds the Woo URL-pass-through shape when publicImageUrl is set', function (): void {
    $product = (new Product())->forceFill([
        'slug' => 'logitech-meetup',
        'name' => 'Logitech MeetUp Video Conferencing System',
    ]);

    $url = 'https://ops.meetingstore.co.uk/storage/auto-create-images/logitech-meetup-main.webp';

    $builder = new ImagePayloadBuilder();
    $payload = $builder->build($product, $url);

    expect($payload)->toBe([
        'images' => [
            [
                'src' => $url,
                'name' => 'logitech-meetup main',
                'alt' => 'Logitech MeetUp Video Conferencing System',
            ],
        ],
    ]);
});

it('handles products with a null slug by producing a trimmed "main" name', function (): void {
    $product = (new Product())->forceFill([
        'slug' => null,
        'name' => 'Unnamed Product',
    ]);

    $builder = new ImagePayloadBuilder();
    $payload = $builder->build($product, 'https://example.com/img.webp');

    expect($payload['images'][0]['name'])->toBe('main');
    expect($payload['images'][0]['alt'])->toBe('Unnamed Product');
});
