<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WpRestClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260708-b4f — products:reconcile-woo-maintenance (Pass 1).
 *
 * Pages Woo `GET /products` (status=publish) and mirrors each returned product's
 * REAL Woo state into the local woo_* columns, matched by woo_product_id:
 *   - woo_image_count    = count(images)
 *   - woo_gtin           = global_unique_id (WC 9.x GTIN; null when blank)
 *   - woo_category_count = count(categories)
 *   - woo_stock_status   = stock_status
 *   - woo_reconciled_at  = now()
 *
 * Quick task 260708-dyy (Pass A) adds a SECOND, read-only pass AFTER the WC pass:
 * it pages the WP REST product list (`GET wp/v2/product`, status=publish,
 * _fields=id,product_brand) via WpRestClient and sets:
 *   - woo_brand_count = count(product_brand term-id array)
 * product_brand is the taxonomy that drives the storefront Brand: link and is the
 * one gap NOT in the WC /products response, hence the separate WP REST pass. The
 * WC pass + all its counters are UNCHANGED.
 *
 * WHY — the local product mirror never captured media/EAN/category state for the
 * 3,614 legacy WC-migrated products, so the Maintenance dashboard (Pass 2) would
 * report those as gaps when Woo actually HAS the data. Reconciling Woo's true
 * state lets Pass 2 report REAL shop-wide gaps across ALL ~4,612 live products.
 *
 * READ-ONLY on Woo — this command only ever issues GETs. It NEVER calls
 * put()/post()/patch()/delete(). The feature test binds a stub whose write
 * methods throw, so a future regression that gains a Woo write fails the suite.
 *
 * Per-page try/catch: one bad page (timeout / 5xx) logs a warning and breaks the
 * loop rather than aborting mid-catalogue with a fatal — the partial reconcile
 * still lands and the nightly run retries the rest tomorrow.
 *
 *   php artisan products:reconcile-woo-maintenance --dry-run     # preview matches
 *   php artisan products:reconcile-woo-maintenance               # full read-only reconcile
 *   php artisan products:reconcile-woo-maintenance --max-pages=2 # cap pages
 */
// Not `final` so tests / future callers can extend if needed (mirrors
// HydrateProductStockFromOffersCommand).
class ReconcileWooMaintenanceCommand extends BaseCommand
{
    protected $signature = 'products:reconcile-woo-maintenance
        {--per-page=100 : Woo products per page (max 100)}
        {--max-pages=0 : Cap pages (0 = until exhausted)}
        {--dry-run : Page Woo + report matches, write nothing}';

    protected $description = 'Mirror each live product\'s real Woo state (image count, EAN, category count, stock) into local woo_* columns for the Maintenance dashboard. Read-only on Woo (GETs).';

    public function __construct(
        private readonly WooClient $woo,
        // 260708-dyy — brand reconciliation Pass A: the product_brand taxonomy
        // (the storefront Brand link) lives on the WP REST product list, NOT the
        // WC /products response, so the brand pass needs the WordPress client.
        private readonly WpRestClient $wp,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $perPage = max(1, min(100, (int) $this->option('per-page')));
        $maxPages = max(0, (int) $this->option('max-pages'));
        $dry = (bool) $this->option('dry-run');

        $this->info(
            ($dry ? '[dry-run] ' : '[LIVE] ')
            .'products:reconcile-woo-maintenance — per_page='.$perPage
            .($maxPages > 0 ? ' max_pages='.$maxPages : ' max_pages=∞')
            .' (read-only on Woo)'
        );

        $pages = 0;
        $scanned = 0;
        $matched = 0;
        $updated = 0;
        $unmatched = 0;
        // 260708-dyy — count of products whose woo_brand_count was written by the
        // WP-REST brand pass (below, after the WC pass).
        $brandUpdated = 0;

        $page = 1;
        while (true) {
            try {
                $rows = $this->woo->get('products', [
                    'status' => 'publish',
                    'per_page' => $perPage,
                    'page' => $page,
                    '_fields' => 'id,images,global_unique_id,categories,stock_status',
                ]);
            } catch (\Throwable $e) {
                $this->warn("Woo GET /products page {$page} failed: {$e->getMessage()} — stopping (partial reconcile retained).");
                Log::warning('reconcile_woo_maintenance.page_failed', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                break;
            }

            if (! is_array($rows) || $rows === []) {
                break;
            }

            $pages++;

            foreach ($rows as $p) {
                // Woo list items are stdClass (normaliseResponseBody returns an
                // already-array /products response as-is, so items stay objects);
                // cast so $p['id'] etc. work. No-op if a caller passes an array.
                $p = (array) $p;
                $wooId = (int) ($p['id'] ?? 0);
                if ($wooId <= 0) {
                    continue;
                }
                $scanned++;

                $product = Product::where('woo_product_id', $wooId)->first();
                if ($product === null) {
                    $unmatched++;

                    continue;
                }
                $matched++;

                $updates = [
                    'woo_image_count' => count((array) ($p['images'] ?? [])),
                    'woo_gtin' => ($g = trim((string) ($p['global_unique_id'] ?? ''))) !== '' ? $g : null,
                    'woo_category_count' => count((array) ($p['categories'] ?? [])),
                    'woo_stock_status' => ($s = trim((string) ($p['stock_status'] ?? ''))) !== '' ? $s : null,
                    'woo_reconciled_at' => now(),
                ];

                if (! $dry) {
                    $product->forceFill($updates)->saveQuietly();
                    $updated++;
                }
            }

            // Short page = last page.
            if (count($rows) < $perPage) {
                break;
            }

            $page++;
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }
        }

        // ── Brand pass (260708-dyy): real product_brand presence via WP REST ──
        //
        // product_brand is the taxonomy that drives meetingstore.co.uk's clickable
        // Brand: link — and it is the ONE gap NOT in the WC /products response, so
        // it needs a separate WP REST pass over the same publish set, matched by
        // woo_product_id. READ-ONLY (GET only). Per-page try/catch mirrors the WC
        // pass: one bad page logs a warning and breaks (partial reconcile retained).
        $bPage = 1;
        while (true) {
            try {
                $brandRows = $this->wp->get('wp/v2/product', [
                    'status' => 'publish',
                    'per_page' => $perPage,
                    'page' => $bPage,
                    '_fields' => 'id,product_brand',
                ]);
            } catch (\Throwable $e) {
                $this->warn("WP REST product page {$bPage} failed: {$e->getMessage()} — stopping (partial reconcile retained).");
                Log::warning('reconcile_woo_maintenance.wp_page_failed', [
                    'page' => $bPage,
                    'error' => $e->getMessage(),
                ]);

                break;
            }

            if (! is_array($brandRows) || $brandRows === []) {
                break;
            }

            foreach ($brandRows as $p) {
                // WpRestClient decodes to arrays, but cast defensively (mirrors the
                // WC-pass stdClass fix) so a future client change can't crash here.
                $p = (array) $p;
                $wooId = (int) ($p['id'] ?? 0);
                if ($wooId <= 0) {
                    continue;
                }

                $product = Product::where('woo_product_id', $wooId)->first();
                if ($product === null) {
                    continue;
                }

                if (! $dry) {
                    $product->forceFill([
                        'woo_brand_count' => count((array) ($p['product_brand'] ?? [])),
                    ])->saveQuietly();
                    $brandUpdated++;
                }
            }

            // Short page = last page.
            if (count($brandRows) < $perPage) {
                break;
            }

            $bPage++;
            if ($maxPages > 0 && $bPage > $maxPages) {
                break;
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['pages', $pages],
                ['scanned', $scanned],
                ['matched', $matched],
                ['updated', $updated],
                ['unmatched', $unmatched],
                ['brand_updated', $brandUpdated],
                ['dry_run', $dry ? 'yes' : 'no'],
            ],
        );

        return SymfonyCommand::SUCCESS;
    }
}
