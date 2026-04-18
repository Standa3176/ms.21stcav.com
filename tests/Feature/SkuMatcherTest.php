<?php

declare(strict_types=1);

use App\Domain\Sync\Services\SkuMatcher;

// -----------------------------------------------------------------------------
// M1: build + match basic hashmap semantics
// -----------------------------------------------------------------------------
test('M1: build(feed)->match(sku) returns supplier row or null', function () {
    $matcher = (new SkuMatcher())->build([
        'SKU-123' => ['price' => '10.00', 'stock' => 5],
        'SKU-456' => ['price' => '20.00', 'stock' => 2],
    ]);

    expect($matcher->match('SKU-123'))->toBe(['price' => '10.00', 'stock' => 5])
        ->and($matcher->match('SKU-NONE'))->toBeNull()
        ->and($matcher->count())->toBe(2)
        ->and($matcher->supplierSkus())->toEqualCanonicalizing(['SKU-123', 'SKU-456']);
});

// -----------------------------------------------------------------------------
// M2: case-sensitive matching
// -----------------------------------------------------------------------------
test('M2: matcher is case-sensitive on SKUs', function () {
    $matcher = (new SkuMatcher())->build([
        'Widget-A' => ['price' => '1.00', 'stock' => 1],
    ]);

    expect($matcher->match('Widget-A'))->not->toBeNull()
        ->and($matcher->match('widget-a'))->toBeNull()
        ->and($matcher->match('WIDGET-A'))->toBeNull();
});

// -----------------------------------------------------------------------------
// M3: perf sanity — 10k SKUs build + 10k matches under 100ms
// -----------------------------------------------------------------------------
test('M3: 10k-SKU feed build + 10k matches runs under 100ms', function () {
    $feed = [];
    for ($i = 0; $i < 10_000; $i++) {
        $feed["SKU-{$i}"] = ['price' => '1.00', 'stock' => $i];
    }

    $start = microtime(true);
    $matcher = (new SkuMatcher())->build($feed);
    for ($i = 0; $i < 10_000; $i++) {
        $matcher->match("SKU-{$i}");
    }
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($elapsedMs)->toBeLessThan(100.0);
});
