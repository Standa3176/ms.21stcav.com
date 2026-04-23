<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductImageFetcher;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Phase 6 Plan 02 Task 2 — ProductImageFetcher tests.
 *
 * Covers Pitfall P6-A mitigations:
 *   - HEAD pre-flight + Content-Type starts-with 'image/' check
 *   - Size bounds guard (min 5KB, max 10MB per config/product_auto_create.php)
 *   - IntegrationLogger row per attempt (channel=woo-auto-create)
 *   - Fallback chain walk — primary fails → fallback[0] tried → first success returns
 *
 * Decode validation is NOT this class's responsibility (that's the Processor's
 * job). This class validates TRANSPORT + size bounds only.
 */
function sampleImageBytes(): string
{
    $bytes = file_get_contents(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    return $bytes !== false ? $bytes : str_repeat('x', 20_000);
}

it('returns the first validated tmp path when primary 404s and fallback 200s with image/*', function (): void {
    Http::fake([
        'https://supplier.cdn.com/missing.jpg' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://supplier.cdn.com/fallback.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])      // HEAD
            ->push(sampleImageBytes(), 200, ['Content-Type' => 'image/jpeg']), // GET
    ]);

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch(
        primaryUrl: 'https://supplier.cdn.com/missing.jpg',
        fallbackUrls: [
            'https://supplier.cdn.com/fallback.jpg',
            'https://supplier.cdn.com/never-queried.jpg',
        ],
    );

    expect($path)->not->toBeNull();
    expect(is_string($path))->toBeTrue();
    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThanOrEqual(5120);

    // Two integration_events rows — one per attempted URL (primary fail + fallback[0] success).
    // fallback[1] never queried because fallback[0] succeeded.
    expect(IntegrationEvent::where('channel', 'woo-auto-create')
        ->where('operation', 'like', 'image.fetch.attempt.%')
        ->count())->toBe(2);

    expect(IntegrationEvent::where('operation', 'image.fetch.attempt.1')
        ->where('status', 'failed')->count())->toBe(1);
    expect(IntegrationEvent::where('operation', 'image.fetch.attempt.2')
        ->where('status', 'success')->count())->toBe(1);

    @unlink($path);
});

it('returns null when every URL in the chain 404s (caller uses placeholder)', function (): void {
    Http::fake([
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch(
        primaryUrl: 'https://supplier.cdn.com/a.jpg',
        fallbackUrls: ['https://supplier.cdn.com/b.jpg', 'https://supplier.cdn.com/c.jpg'],
    );

    expect($path)->toBeNull();

    // 3 failed attempts logged
    expect(IntegrationEvent::where('channel', 'woo-auto-create')
        ->where('status', 'failed')
        ->count())->toBe(3);
});

it('rejects a URL whose HEAD returns Content-Type text/html (Pitfall P6-A)', function (): void {
    $html = file_get_contents(base_path('tests/Fixtures/ProductAutoCreate/tiny.html'));

    Http::fake([
        'https://supplier.cdn.com/trap.jpg' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch('https://supplier.cdn.com/trap.jpg');

    expect($path)->toBeNull();

    $event = IntegrationEvent::where('operation', 'image.fetch.attempt.1')->firstOrFail();
    expect($event->status)->toBe('failed');
    expect($event->response_body)->toMatchArray(['reason' => 'non_image_content_type']);
});

it('rejects a GET body below the min-size floor (Pitfall P6-A size guard — HTML error page)', function (): void {
    // 1KB body with image/jpeg Content-Type — probably an error page mislabelled
    $tiny = str_repeat("\x00\x01\x02\x03", 250); // 1000 bytes

    Http::fake([
        'https://supplier.cdn.com/suspicious.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])     // HEAD
            ->push($tiny, 200, ['Content-Type' => 'image/jpeg']), // GET 1KB
    ]);

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch('https://supplier.cdn.com/suspicious.jpg');

    expect($path)->toBeNull();

    $event = IntegrationEvent::where('operation', 'image.fetch.attempt.1')->firstOrFail();
    expect($event->status)->toBe('failed');
    expect($event->response_body)->toMatchArray(['reason' => 'size_below_floor']);
});

it('rejects a GET body above the max-size ceiling (Pitfall P6-A DoS guard)', function (): void {
    // Temporarily lower the max to 8KB so we don't have to synthesize 11MB in memory
    config(['product_auto_create.max_image_bytes' => 8 * 1024]);
    config(['product_auto_create.min_image_bytes' => 100]);

    $big = str_repeat("\x7F", 12 * 1024); // 12KB — above the temporary 8KB ceiling

    Http::fake([
        'https://supplier.cdn.com/huge.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push($big, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch('https://supplier.cdn.com/huge.jpg');

    expect($path)->toBeNull();

    $event = IntegrationEvent::where('operation', 'image.fetch.attempt.1')->firstOrFail();
    expect($event->response_body)->toMatchArray(['reason' => 'size_above_ceiling']);
});

it('catches transport exceptions + falls through + logs reason=exception', function (): void {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('simulated DNS failure');
    });

    $fetcher = app(ProductImageFetcher::class);
    $path = $fetcher->fetch('https://supplier.cdn.com/unreachable.jpg');

    expect($path)->toBeNull();

    $event = IntegrationEvent::where('operation', 'image.fetch.attempt.1')->firstOrFail();
    expect($event->status)->toBe('failed');
    expect($event->response_body)->toMatchArray(['reason' => 'exception']);
});

it('logs channel=woo-auto-create + per-attempt counter on every attempt', function (): void {
    Http::fake([
        'https://supplier.cdn.com/primary.jpg' => Http::response('', 404),
        'https://supplier.cdn.com/fallback.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push(sampleImageBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    app(ProductImageFetcher::class)->fetch(
        primaryUrl: 'https://supplier.cdn.com/primary.jpg',
        fallbackUrls: ['https://supplier.cdn.com/fallback.jpg'],
    );

    $events = IntegrationEvent::where('channel', 'woo-auto-create')->get();
    expect($events)->toHaveCount(2);
    expect($events->pluck('operation')->all())
        ->toContain('image.fetch.attempt.1')
        ->toContain('image.fetch.attempt.2');
});

it('skips empty / null URLs in the fallback array without an HTTP call', function (): void {
    Http::fake([
        'https://supplier.cdn.com/valid.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push(sampleImageBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $path = app(ProductImageFetcher::class)->fetch(
        primaryUrl: '',
        fallbackUrls: [null, 'https://supplier.cdn.com/valid.jpg'],
    );

    expect($path)->not->toBeNull();

    // First two attempts are the empty/null skips — they get logged as failed but
    // no HTTP call happens.
    Http::assertSentCount(2); // HEAD + GET for the valid URL only

    @unlink($path);
});
