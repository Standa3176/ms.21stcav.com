<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\ProductAutoCreate\Exceptions\ImageFetchFailedException;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Spatie\ImageOptimizer\OptimizerChainFactory;

/**
 * Phase 6 Plan 02 — AUTO-04 (image pipeline: resize + WebP + EXIF strip).
 *
 * v3 API — see 06-RESEARCH.md Pitfall P6-B:
 *   - ->scaleDown(width: 1200, height: 1200)  ← v3 "max-fit no-upscale"
 *   - ->toWebp(quality: 85, strip: true)      ← v3 encode + strip EXIF in one call
 *   - NEVER ->fit()  (v2 only — v3 renamed to ->cover())
 *   - NEVER ->encode() (v2 only — v3 uses ->toWebp() / ->toJpeg() / etc.)
 *
 * Pitfall P6-C — spatie/image-optimizer binary pass is OPTIONAL + Windows-graceful:
 *   - Config flag `product_auto_create.optimize_images` gates invocation; default
 *     false on Windows, true on Linux VPS (see config/product_auto_create.php).
 *   - Wrapped in try/catch — ProcessFailedException or missing binaries log
 *     'optimizer_unavailable' + continue with the pre-optimized WebP bytes.
 *
 * DecoderException (intervention's bad-bytes signal) is INTENTIONALLY left
 * to propagate — the caller (ProcessAutoCreateImageJob) distinguishes
 * transport failure (fetcher returned null) from decode failure (this throws)
 * via the exception type.
 */
final class ProductImageProcessor
{
    public function __construct(
        private ImageManager $manager,
    ) {}

    /**
     * Read the binary at $inputPath, apply the v3 pipeline, return the WebP bytes.
     *
     * @throws ImageFetchFailedException                      If the input file is unreadable.
     * @throws \Intervention\Image\Exceptions\DecoderException If the bytes can't be decoded.
     */
    public function process(string $inputPath): string
    {
        $bytes = @file_get_contents($inputPath);
        if ($bytes === false) {
            throw new ImageFetchFailedException("Could not read image at: {$inputPath}");
        }

        // intervention/image v3 — read() throws DecoderException on malformed input.
        $image = $this->manager->read($bytes);

        // Pitfall P6-B — v3 method drift. scaleDown = fit-in-box, preserve aspect, NO upscale.
        $maxDim = (int) config('product_auto_create.image_max_dimension', 1200);
        $image->scaleDown(width: $maxDim, height: $maxDim);

        // WebP encode + strip EXIF atomically.
        $quality = (int) config('product_auto_create.image_webp_quality', 85);
        $encoded = (string) $image->toWebp(quality: $quality, strip: true);

        if ($encoded === '') {
            throw new ImageFetchFailedException('Intervention returned empty WebP bytes');
        }

        // Pitfall P6-C — optional spatie optimizer pass. Config-gated + Windows-graceful.
        if ($this->shouldOptimize()) {
            $optimised = $this->tryOptimize($encoded);
            if ($optimised !== null) {
                return $optimised;
            }
        }

        return $encoded;
    }

    /**
     * Config flag + OS check. Returns true only when optimizer binaries are
     * reasonably expected to be present (Linux VPS). False on Windows dev +
     * whenever the admin disables the optimizer step explicitly.
     */
    private function shouldOptimize(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return (bool) config('product_auto_create.optimize_images', true);
    }

    /**
     * Attempt the spatie optimizer pass on the encoded bytes. Writes a
     * temp file (optimizer operates in-place on the path), runs the chain,
     * reads the result. Any throwable logs 'optimizer_unavailable' and
     * returns null — the caller uses the pre-optimized bytes instead.
     */
    private function tryOptimize(string $encodedWebp): ?string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'webp-optim-');
        if ($tmpBase === false) {
            return null;
        }

        $tmp = $tmpBase.'.webp';
        @unlink($tmpBase);

        if (@file_put_contents($tmp, $encodedWebp) === false) {
            @unlink($tmp);

            return null;
        }

        try {
            $chain = OptimizerChainFactory::create();
            $chain->optimize($tmp);

            $optimised = @file_get_contents($tmp);
            @unlink($tmp);

            if ($optimised === false || $optimised === '') {
                return null;
            }

            return $optimised;
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::warning('product_auto_create.optimizer_unavailable', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
