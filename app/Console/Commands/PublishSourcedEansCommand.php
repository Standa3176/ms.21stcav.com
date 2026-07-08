<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooGtinPublisher;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * 260708-pw3 — publish ALREADY-BACKFILLED local EANs to Woo's GTIN
 * (global_unique_id) with ZERO re-lookup cost.
 *
 * Why this exists: before the end-to-end WooGtinPublisher fix (260708-pw3)
 * shipped, backfilling an EAN (products:backfill-merchant-feed) only wrote it
 * LOCALLY — no path pushed it to a LIVE product's GTIN (resync doesn't carry the
 * GTIN; global_unique_id was only written on the initial auto-create POST). So
 * the operator's in-flight backfill runs build a backlog of local EANs that
 * never reach Woo (the Woo Maintenance 'missing EAN' count stays up).
 *
 * This command is the cheap counterpart to products:publish-sourced-images: it
 * reads Product.ean and hands it straight to WooGtinPublisher::publish() — a
 * single Woo products-PUT of {global_unique_id} that bumps woo_gtin. No
 * supplier_db / EAN-search / Icecat lookups are ever touched.
 *
 * Selection:
 *   --skus=A,B,C  → target exactly those SKUs (force-publish, even if they
 *                   already have a Woo GTIN).
 *   (no --skus)   → auto-target the backlog: live products (status=publish,
 *                   woo_product_id NOT NULL) with a non-empty local ean that
 *                   are still missing on Woo (woo_gtin NULL). Products that
 *                   already have a GTIN are left alone, so re-running is
 *                   idempotent.
 *   --dry-run     → list the selection + counts; NO Woo write.
 *   --limit=N     → cap how many are processed (0 = no cap).
 *
 * WC 9.x rejects DUPLICATE GTINs (suppliers share one EAN across variants) — the
 * publisher reports those as 'collision' and clears the local ean so it stops
 * colliding. That is expected, not an error.
 *
 *   php artisan products:publish-sourced-eans --dry-run          (preview count)
 *   php artisan products:publish-sourced-eans                    (publish all missing-on-Woo)
 *   php artisan products:publish-sourced-eans --skus=ABC,DEF     (target specific SKUs)
 *   php artisan products:publish-sourced-eans --limit=20         (first batch)
 */
final class PublishSourcedEansCommand extends BaseCommand
{
    protected $signature = 'products:publish-sourced-eans
        {--skus= : Comma-separated SKUs to publish. If omitted, targets ALL live products with a local EAN not yet on Woo.}
        {--dry-run : List the selection + counts; do NOT write to Woo.}
        {--limit=0 : Max products to process (0 = no cap).}';

    protected $description = 'Publish already-backfilled local EANs to Woo (global_unique_id) with zero re-lookup cost — reuses WooGtinPublisher';

    public function __construct(
        private readonly WooGtinPublisher $publisher,
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
            ->whereNotNull('ean')
            ->where('ean', '!=', '');

        if ($skusOpt !== '') {
            $skus = array_values(array_filter(
                array_map('trim', explode(',', $skusOpt)),
                static fn (string $s): bool => $s !== '',
            ));
            $query->whereIn('sku', $skus);
        } else {
            // auto: only those still missing the GTIN ON Woo (don't re-push
            // products that already have one). whereNull keeps this
            // driver-portable (SQLite tests / MariaDB prod).
            $query->whereNull('woo_gtin');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();
        $this->info(($dryRun ? 'DRY-RUN — ' : '').'Publishing local EANs to Woo GTIN for '.$products->count().' product(s).');

        $published = 0;
        $collision = 0;
        $skipped = 0;
        foreach ($products as $product) {
            if ($dryRun) {
                $this->line('  would publish '.$product->sku.' (Woo #'.$product->woo_product_id.', EAN '.$product->ean.')');

                continue;
            }
            switch ($this->publisher->publish($product, $product->ean)) {
                case 'published':
                    $published++;
                    $this->info('  ✓ '.$product->sku.' → Woo #'.$product->woo_product_id.' (GTIN '.$product->ean.')');
                    break;
                case 'collision':
                    $collision++;
                    $this->warn('  · '.$product->sku.' collision (duplicate GTIN — local EAN cleared)');
                    break;
                default:
                    $skipped++;
                    $this->warn('  · '.$product->sku.' skipped (not live on Woo or no local EAN)');
                    break;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'DRY-RUN complete — '.$products->count().' would be processed. Re-run without --dry-run to publish.'
            : "Done — {$published} published, {$collision} collisions (duplicate GTIN — local EAN cleared), {$skipped} skipped.");

        return SymfonyCommand::SUCCESS;
    }
}
