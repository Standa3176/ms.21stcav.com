<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Products\Models\Product;
use Illuminate\View\View;

/**
 * Renders a LOCAL draft Product as a customer-facing product page so operators
 * can sign off on auto-generated content + sourced images BEFORE any Woo push.
 *
 * Auth-gated (admin panel session). Reads only local data — never touches Woo.
 * The stored short/long descriptions are our own AI-generated HTML, rendered
 * unescaped so the bullets + <h3> sections display as they will on the shop.
 */
final class ProductPreviewController extends Controller
{
    public function __invoke(Product $product): View
    {
        $gallery = is_array($product->gallery_image_urls) ? $product->gallery_image_urls : [];
        if ($gallery === [] && (string) $product->image_url !== '') {
            $gallery = [(string) $product->image_url];
        }
        $gallery = array_values(array_unique(array_filter($gallery, static fn ($u): bool => is_string($u) && $u !== '')));
        if ($gallery === []) {
            $gallery = [(string) config('product_auto_create.placeholder_image_url')];
        }

        return view('preview.product', [
            'product' => $product,
            'gallery' => $gallery,
        ]);
    }
}
