<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductContentBuilder;

it('compiles the full template when all supplier keys are present', function (): void {
    $builder = app(ProductContentBuilder::class);

    $result = $builder->compile([
        'name' => 'MeetUp',
        'brand' => 'Logitech',
        'category' => 'Video Conferencing',
        'short_tagline' => 'All-in-one video bar',
        'overview' => 'Overview text',
        'features' => ['Feature A', 'Feature B'],
        'specs' => ['Width' => '400mm', 'Weight' => '1 kg'],
        'box_contents' => ['Camera', 'Cable'],
    ]);

    expect($result)->toHaveKeys(['title', 'slug', 'meta_description', 'short_description', 'long_description']);

    expect($result['title'])->toBe('Logitech MeetUp Video Conferencing');
    expect($result['slug'])->toBe('logitech-meetup-video-conferencing');
    expect($result['meta_description'])->toStartWith('Logitech MeetUp');
    expect(mb_strlen($result['meta_description']))->toBeLessThanOrEqual(160);
    expect($result['short_description'])->toContain('<ul>')
        ->and($result['short_description'])->toContain('<li>Feature A</li>');
    expect($result['long_description'])->toContain('<h2>Overview</h2>')
        ->and($result['long_description'])->toContain('<h2>Key Features</h2>')
        ->and($result['long_description'])->toContain('<h2>Technical Specifications</h2>')
        ->and($result['long_description'])->toContain("<h2>What's in the Box</h2>");
});

it('skips the Key Features section when supplier has no features', function (): void {
    $builder = app(ProductContentBuilder::class);

    $result = $builder->compile([
        'name' => 'MeetUp',
        'brand' => 'Logitech',
        'category' => 'Video Conferencing',
        'overview' => 'Overview only',
        // features deliberately absent
    ]);

    expect($result['long_description'])->toContain('<h2>Overview</h2>');
    expect($result['long_description'])->not->toContain('<h2>Key Features</h2>');
    expect($result['short_description'])->toBe('');
});

it('truncates meta_description over 160 chars with an ellipsis', function (): void {
    $builder = app(ProductContentBuilder::class);

    // Force long tagline so meta definitely exceeds 160 chars.
    $result = $builder->compile([
        'name' => 'MeetUp',
        'brand' => 'Logitech',
        'category' => 'Video Conferencing',
        'short_tagline' => str_repeat('very long tagline content ', 15),
    ]);

    expect(mb_strlen($result['meta_description']))->toBe(160);
    expect($result['meta_description'])->toEndWith('…');
});

it('returns empty sections when supplier data is entirely empty', function (): void {
    $builder = app(ProductContentBuilder::class);

    $result = $builder->compile([]);

    expect($result)->toHaveKeys(['title', 'slug', 'meta_description', 'short_description', 'long_description']);
    expect($result['title'])->toBe('');
    expect($result['slug'])->toBe('');
    expect($result['short_description'])->toBe('');
    expect($result['long_description'])->toBe('');
});
