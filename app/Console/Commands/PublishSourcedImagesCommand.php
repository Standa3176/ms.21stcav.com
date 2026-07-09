<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\WooGalleryPublisher;
use App\Domain\Products\Models\Product;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * 260708-mv0 — publish ALREADY-SOURCED local galleries to Woo with ZERO
 * re-sourcing cost.
 *
 * Why this exists: before the end-to-end WooGalleryPublisher fix (260708-kg4)
 * shipped, the operator sourced ~180 product galleries locally, but they never
 * reached Woo (placeholders stayed up, woo_image_count = 0). Re-running the
 * Catalogue Gaps "Source images" fix would re-source them — paying Icecat / web
 * / Claude-vision all over again for images we already have on disk.
 *
 * This command is the cheap counterpart: it reads Product.gallery_image_urls and
 * hands them straight to WooGalleryPublisher::publish() — a single Woo images-PUT
 * that REPLACES the gallery (removing the placeholder) and bumps woo_image_count.
 * No sourcing providers are ever touched.
 *
 * Selection:
 *   --skus=A,B,C  → target exactly those SKUs (force-publish, even if they
 *                   already have Woo images).
 *   (no --skus)   → auto-target the backlog: live products (status=publish,
 *                   woo_product_id NOT NULL) with a non-empty local gallery that
 *                   are still missing on Woo (woo_image_count = 0 OR NULL).
 *                   Products that already have Woo images are left alone, so
 *                   re-running is idempotent.
 *   --dry-run     → list the selection + counts; NO Woo write.
 *   --limit=N     → cap how many are processed (0 = no cap).
 *
 * The publisher already skips non-live (no woo_product_id) + empty-gallery
 * products and never pushes a placeholder — the command just reports counts.
 *
 *   php artisan products:publish-sourced-images --dry-run          (preview count)
 *   php artisan products:publish-sourced-images                    (publish all missing-on-Woo)
 *   php artisan products:publish-sourced-images --skus=ABC,DEF     (target specific SKUs)
 *   php artisan products:publish-sourced-images --limit=20         (first batch)
 */
final class PublishSourcedImagesCommand extends BaseCommand
{
    protected $signature = 'products:publish-sourced-images
        {--skus= : Comma-separated SKUs to publish. If omitted, targets ALL live products with a local gallery not yet on Woo.}
        {--dry-run : List the selection + counts; do NOT write to Woo.}
        {--limit=0 : Max products to process (0 = no cap).}';

    protected $description = 'Publish already-sourced local galleries to Woo (replace placeholders) with zero re-sourcing cost — reuses WooGalleryPublisher';

    public function __construct(
        private readonly WooGalleryPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $skusOpt = trim((string) $this->option('skus'));
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id')
            ->whereNotNull('gallery_image_urls')
            ->where('gallery_image_urls', '!=', '[]')
            ->where('gallery_image_urls', '!=', '');

        if ($skusOpt !== '') {
            $skus = array_values(array_filter(
                array_map('trim', explode(',', $skusOpt)),
                static fn (string $s): bool => $s !== '',
            ));
            $query->whereIn('sku', $skus);
        } else {
            // auto: only those still missing images ON Woo (don't re-push products
            // that already have Woo images). whereNull-or-zero closure keeps this
            // driver-portable (SQLite tests / MariaDB prod).
            $query->where(fn ($q) => $q->whereNull('woo_image_count')->orWhere('woo_image_count', 0));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();
        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Publishing local galleries to Woo for '.$products->count().' product(s).');

        $published = 0;
        $skipped = 0;
        foreach ($products as $product) {
            if ($dryRun) {
                $this->line('  would publish '.$product->sku.' (Woo #'.$product->woo_product_id.', '.count((array) $product->gallery_image_urls).' image(s))');

                continue;
            }
            if ($this->publisher->publish($product, (array) $product->gallery_image_urls)) {
                $published++;
                $this->info('  ✓ '.$product->sku.' → Woo #'.$product->woo_product_id.' (placeholder replaced)');
            } else {
                $skipped++;
                $this->warn('  · '.$product->sku.' skipped (not live on Woo or no local images)');
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'DRY-RUN complete — '.$products->count().' would be processed. Re-run without --dry-run to publish.'
            : "Done — {$published} published, {$skipped} skipped.");

        return SymfonyCommand::SUCCESS;
    }
}
