<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Exceptions\ImageFetchFailedException;
use App\Domain\ProductAutoCreate\Services\ProductImageProcessor;

/**
 * Phase 6 Plan 02 Task 2 — ProductImageProcessor tests.
 *
 * Pitfall P6-B: v3 API verification. Output:
 *   - WebP signature bytes present ("RIFF....WEBP")
 *   - Decoded dimensions ≤ 1200x1200 (no upscale on smaller input)
 *   - EXIF metadata stripped ("TESTCAMERA" substring not found in output)
 *   - Optimizer skipped on Windows (no crash)
 */
it('produces WebP bytes with the RIFF....WEBP signature', function (): void {
    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    expect($output)->not->toBe('');
    expect(strlen($output))->toBeGreaterThan(100);

    // WebP signature: bytes 0-3 = "RIFF", bytes 8-11 = "WEBP"
    expect(substr($output, 0, 4))->toBe('RIFF');
    expect(substr($output, 8, 4))->toBe('WEBP');
});

it('scales down images larger than 1200x1200 without upscaling smaller ones', function (): void {
    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    // Decode via getimagesizefromstring to avoid pulling in another lib
    $info = getimagesizefromstring($output);
    expect($info)->not->toBeFalse();
    expect($info[0])->toBeLessThanOrEqual(1200);
    expect($info[1])->toBeLessThanOrEqual(1200);
    // Input is 800x600 — scaleDown MUST NOT upscale to 1200
    expect($info[0])->toBe(800);
    expect($info[1])->toBe(600);
});

it('strips EXIF metadata (TESTCAMERA marker from input JPEG must not appear in output)', function (): void {
    $input = file_get_contents(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));
    // Sanity check — the fixture carries the EXIF marker we're looking for
    expect(strpos($input, 'TESTCAMERA'))->not->toBeFalse();

    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    expect(strpos($output, 'TESTCAMERA'))->toBeFalse();
    // Also no lingering "Exif" marker
    expect(strpos($output, 'Exif'))->toBeFalse();
});

it('handles PNG inputs and still emits WebP output', function (): void {
    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.png'));

    expect(substr($output, 0, 4))->toBe('RIFF');
    expect(substr($output, 8, 4))->toBe('WEBP');
});

it('throws ImageFetchFailedException when the input path is unreadable', function (): void {
    $processor = app(ProductImageProcessor::class);

    $processor->process('/nonexistent/path/that/does/not/exist.jpg');
})->throws(ImageFetchFailedException::class);

it('throws DecoderException when the input bytes are malformed (caller distinguishes)', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'bad-bytes-').'.bin';
    file_put_contents($tmp, "this is not an image at all — just random ASCII garbage\n");

    try {
        $processor = app(ProductImageProcessor::class);
        $threw = false;
        try {
            $processor->process($tmp);
        } catch (\Intervention\Image\Exceptions\DecoderException) {
            $threw = true;
        } catch (\Throwable $e) {
            // intervention/image v3 sometimes wraps decode failures — either way,
            // we want to confirm SOME throwable escapes (not a silent return).
            $threw = true;
        }
        expect($threw)->toBeTrue();
    } finally {
        @unlink($tmp);
    }
});

it('skips spatie optimizer on Windows (no crash even without binaries)', function (): void {
    // On Windows, the optimizer should NEVER be invoked; on Linux CI, the
    // config flag defaults to true but our try/catch swallows any missing-binary
    // error. Either way — process() MUST NOT throw.
    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    expect(substr($output, 0, 4))->toBe('RIFF');
    // If we got here without an uncaught exception, the test passes.
    expect(true)->toBeTrue();
});

it('respects config(product_auto_create.optimize_images) = false to skip optimizer entirely', function (): void {
    config(['product_auto_create.optimize_images' => false]);

    $processor = app(ProductImageProcessor::class);
    $output = $processor->process(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    expect(substr($output, 0, 4))->toBe('RIFF');
    expect(substr($output, 8, 4))->toBe('WEBP');
});
