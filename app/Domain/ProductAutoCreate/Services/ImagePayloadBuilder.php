<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Products\Models\Product;

/**
 * Phase 6 Plan 02 — Woo REST `images[]` payload shape for URL pass-through
 * (Q5 resolved — see storage/app/research/woo-image-passthrough.json +
 * tests/Feature/ProductAutoCreate/WooUrlPassthroughSmokeTest.php).
 *
 * Contract:
 *   build(Product $product, ?string $publicImageUrl): array
 *     → ['images' => [{src, name, alt}]]  when publicImageUrl is set
 *     → ['images' => []]                  when publicImageUrl is null
 *
 * Callers merge this into the parent Woo product payload — e.g.:
 *   $payload = array_merge($basePayload, $imagePayloadBuilder->build($p, $url));
 *
 * "URL pass-through" means Woo downloads the $publicImageUrl from its own
 * background worker + stores it in its own media library. Our URL must
 * stay alive for a few seconds post-POST (see WooUrlPassthroughSmokeTest).
 *
 * Empty-array shape is INTENTIONAL — it lets callers always merge the
 * 'images' key into the payload without conditional logic, even when no
 * image is ready yet (e.g. Plan 03's CreateWooProductJob creates a draft
 * WITHOUT images, and ProcessAutoCreateImageJob later PUTs the image).
 */
final class ImagePayloadBuilder
{
    /**
     * @return array{images: array<int, array{src: string, name: string, alt: string}>}
     */
    public function build(Product $product, ?string $publicImageUrl): array
    {
        if ($publicImageUrl === null || $publicImageUrl === '') {
            return ['images' => []];
        }

        $slug = (string) ($product->slug ?? '');
        $name = (string) ($product->name ?? '');

        return [
            'images' => [
                [
                    'src' => $publicImageUrl,
                    'name' => trim($slug.' main'),
                    'alt' => $name,
                ],
            ],
        ];
    }
}
