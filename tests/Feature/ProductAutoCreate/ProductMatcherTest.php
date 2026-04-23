<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\ProductAutoCreate\Services\ProductMatcher;

it('existsNormalised matches ignoring casing + trailing whitespace (AUTO-08)', function (): void {
    Product::factory()->create(['sku' => 'Abc-123']);

    $m = app(ProductMatcher::class);

    expect($m->existsNormalised('abc-123'))->toBeTrue();
    expect($m->existsNormalised('ABC-123'))->toBeTrue();
    expect($m->existsNormalised('  abc-123 '))->toBeTrue();
    expect($m->existsNormalised('abc-124'))->toBeFalse();
});

it('existsCaseInsensitiveSlug ignores whitespace + casing', function (): void {
    Product::factory()->create(['slug' => 'logitech-meetup']);

    $m = app(ProductMatcher::class);

    expect($m->existsCaseInsensitiveSlug('Logitech-MeetUp'))->toBeTrue();
    expect($m->existsCaseInsensitiveSlug('logitech-meetup'))->toBeTrue();
    expect($m->existsCaseInsensitiveSlug('  logitech-meetup  '))->toBeTrue();
    expect($m->existsCaseInsensitiveSlug('logitech-teams'))->toBeFalse();
});

it('existsCaseInsensitiveSlug excludes the passed-in product id', function (): void {
    $p = Product::factory()->create(['slug' => 'logitech-meetup']);
    $m = app(ProductMatcher::class);

    expect($m->existsCaseInsensitiveSlug('logitech-meetup', $p->id))->toBeFalse();
});
