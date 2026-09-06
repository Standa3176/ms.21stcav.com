<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Products\Models\Product;
use App\Foundation\Html\RichTextSanitiser;
use Illuminate\View\View;

/**
 * Renders a LOCAL draft Product as a customer-facing product page so operators
 * can sign off on auto-generated content + sourced images BEFORE any Woo push.
 *
 * Auth-gated (admin panel session). Reads only local data — never touches Woo.
 *
 * The stored short/long descriptions are HTML and must render as markup so the
 * bullets + <h3> sections look like the shop. They are NOT trusted: quick task
 * 260906-hbl corrected the assumption behind the old comment here ("our own
 * AI-generated HTML"). The model writes them from a prompt built out of
 * supplier feed text, so the content originates with a third party and reaches
 * an authenticated admin's browser. RichTextSanitiser allow-lists the markup on
 * the way to the view.
 */
final class ProductPreviewController extends Controller
{
    public function __invoke(Product $product, RichTextSanitiser $sanitiser): View
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
            'sanitiser' => $sanitiser,
        ]);
    }
}
