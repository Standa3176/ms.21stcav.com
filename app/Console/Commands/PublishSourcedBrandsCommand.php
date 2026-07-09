<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\WooBrandPublisher;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * 260709-gj2 — publish ALREADY-SET local brands to Woo's product_brand taxonomy
 * (the storefront Brand link) with ZERO re-derivation cost.
 *
 * Why this exists: a product can carry a local brand_id (WC-native /products/brands
 * term) yet have NO product_brand term assigned on its live Woo product — so the
 * storefront Brand: <link> is empty and the Woo Maintenance 'missing brand' count
 * stays up. Resync re-pushes the product_brand link but ALSO re-writes tags +
 * regular_price + attributes; this command is the cheap, brand-ONLY counterpart to
 * products:publish-sourced-eans / products:publish-sourced-images.
 *
 * It reads Product.brand_id, maps it to the brand NAME via the live Woo brand list
 * (products/brands — the only shared identity across the WC-native and product_brand
 * taxonomies), and hands the name to WooBrandPublisher::publish(), which resolves the
 * product_brand term + assigns it + bumps woo_brand_count. No supplier_db / feed
 * lookups are ever touched.
 *
 * Selection:
 *   --skus=A,B,C  → target exactly those SKUs (force-publish, even if they already
 *                   have a brand on Woo).
 *   (no --skus)   → auto-target the backlog: live products (status=publish,
 *                   woo_product_id NOT NULL) with a local brand_id set that are still
 *                   missing on Woo (woo_brand_count=0). Products that already have a
 *                   brand on Woo are left alone, so re-running is idempotent.
 *   --dry-run     → list the selection + counts; NO Woo write.
 *   --limit=N     → cap how many are processed (0 = no cap).
 *
 * Products with NO local brand_id are OUT OF SCOPE (never selected) — they need brand
 * derivation first (products:backfill-merchant-feed --field=brand). Products whose
 * brand isn't in the product_brand taxonomy yet are reported as 'no brand term'
 * (create the brand first — not auto-created here).
 *
 *   php artisan products:publish-sourced-brands --dry-run          (preview count)
 *   php artisan products:publish-sourced-brands                    (assign product_brand on Woo)
 *   php artisan products:publish-sourced-brands --skus=ABC,DEF     (target specific SKUs)
 *   php artisan products:publish-sourced-brands --limit=20         (first batch)
 */
final class PublishSourcedBrandsCommand extends BaseCommand
{
    protected $signature = 'products:publish-sourced-brands
        {--skus= : Comma-separated SKUs. If omitted, targets ALL live products with a local brand_id not yet on Woo.}
        {--dry-run : List selection + counts; no Woo write.}
        {--limit=0 : Max products (0 = no cap).}';

    protected $description = 'Publish already-set local brands to Woo (product_brand taxonomy) with zero re-derivation cost — reuses WooBrandPublisher';

    public function __construct(
        private readonly WooBrandPublisher $publisher,
        private readonly WooClient $woo,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $skusOpt = trim((string) $this->option('skus'));
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        // Cache brand-id → brand-name from the live Woo brand list ONCE. This is
        // the only shared identity across the WC-native /products/brands taxonomy
        // (local Product.brand_id) and the product_brand taxonomy (the storefront
        // Brand link) — mirrors ResyncProductsToWooCommand.
        $brandNameById = $this->buildBrandNameMap();

        $query = Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id')
            ->whereNotNull('brand_id');

        if ($skusOpt !== '') {
            $skus = array_values(array_filter(
                array_map('trim', explode(',', $skusOpt)),
                static fn (string $s): bool => $s !== '',
            ));
            $query->whereIn('sku', $skus);
        } else {
            // auto: only those still missing the brand ON Woo (don't re-push
            // products that already have one). Driver-portable (SQLite tests /
            // MariaDB prod).
            $query->where('woo_brand_count', 0);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();
        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Publishing local brands to Woo product_brand for '.$products->count().' product(s).');

        $published = 0;
        $noTerm = 0;
        $skipped = 0;
        foreach ($products as $product) {
            $brandName = $brandNameById[(int) $product->brand_id] ?? null;

            if ($dryRun) {
                $this->line('  would publish '.$product->sku.' (Woo #'.$product->woo_product_id.', brand '.($brandName ?? '—unknown—').')');

                continue;
            }

            switch ($this->publisher->publish($product, $brandName)) {
                case 'published':
                    $published++;
                    $this->info('  ✓ '.$product->sku.' → Woo #'.$product->woo_product_id.' (brand '.$brandName.')');
                    break;
                case 'no_term':
                    $noTerm++;
                    $this->warn('  · '.$product->sku.' no product_brand term for "'.$brandName.'" (create the brand first)');
                    break;
                default:
                    $skipped++;
                    $this->warn('  · '.$product->sku.' skipped (not live on Woo or no brand name)');
                    break;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'DRY-RUN complete — '.$products->count().' would be processed. Re-run without --dry-run to publish.'
            : "Done — {$published} published, {$noTerm} no brand term (create the brand first), {$skipped} skipped.");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Build brand-id → brand-name from the live Woo brand list (products/brands),
     * paginated. Mirrors ResyncProductsToWooCommand's brand-name map.
     *
     * @return array<int, string>
     */
    private function buildBrandNameMap(): array
    {
        $map = [];
        $page = 1;
        do {
            try {
                $batch = $this->woo->get('products/brands', ['per_page' => 100, 'page' => $page]);
            } catch (\Throwable) {
                break;
            }
            if (! is_array($batch) || $batch === []) {
                break;
            }
            foreach ($batch as $b) {
                if (is_object($b)) {
                    $b = (array) $b;
                }
                if (! is_array($b)) {
                    continue;
                }
                $id = $b['id'] ?? null;
                $name = (string) ($b['name'] ?? '');
                if (is_numeric($id) && $name !== '') {
                    $map[(int) $id] = $name;
                }
            }
            $page++;
        } while (count($batch) === 100 && $page <= 50);

        return $map;
    }
}
