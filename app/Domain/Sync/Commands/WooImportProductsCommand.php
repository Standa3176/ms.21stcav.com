<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260504-d7v — bulk Woo → local products mirror.
 *
 * Closes the bootstrap gap where the products table couldn't be populated from
 * an existing Woo catalogue. The Phase 2 sync:supplier command UPDATES existing
 * products' buy_price but never CREATES rows; this command does the initial
 * import. Run once per environment to materialise the products table; after
 * that, sync:supplier and the Phase 6 auto-create flow keep it current.
 *
 * Behaviour:
 * - Pages /wc/v3/products at per_page=100. Bypasses WooProductIterator because
 *   the iterator strips name/status/slug/descriptions for the sync:supplier flow.
 * - Product::updateOrCreate on woo_product_id (canonical cross-system identity
 *   per Phase 2 D-01). New rows get sku/name/type/status/stock_status/sell_price
 *   from Woo's response; existing rows update those same fields + last_synced_at.
 * - --with-supplier additionally seeds buy_price from the 21stcav.com supplier
 *   feed when the SKU exists in both — saves a second sync round trip.
 * - Variations skipped (separate task — ProductVariant import has its own
 *   complexity that SyncChunkJob already handles for updates).
 * - --dry-run reports planned changes without touching the DB.
 */
final class WooImportProductsCommand extends BaseCommand
{
    protected $signature = 'woo:import-products
        {--with-supplier : Also enrich buy_price from the 21stcav.com supplier feed}
        {--limit=0 : Stop after N simple products (0 = no limit; useful for smoke-testing)}
        {--dry-run : Report what would change without writing to products table}';

    protected $description = 'Import Woo catalogue into local products table; optionally enrich with supplier buy_price.';

    public function __construct(
        private readonly WooClient $woo,
        private readonly SupplierClient $supplier,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $woo = $this->woo;
        $supplier = $this->supplier;
        $withSupplier = (bool) $this->option('with-supplier');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('woo:import-products — '.($dryRun ? 'DRY-RUN' : 'LIVE')
            .($withSupplier ? ' + supplier-enrichment' : '')
            .($limit > 0 ? " (limit={$limit})" : ''));

        $supplierFeed = [];
        if ($withSupplier) {
            $supplierFeed = $supplier->fetchAllProducts();
            $this->info('Loaded '.count($supplierFeed).' supplier SKUs from 21stcav.com');
        }

        $created = 0;
        $updated = 0;
        $errored = 0;
        $skippedVariation = 0;
        $skippedOther = 0;
        $simpleSeen = 0;
        $page = 1;

        do {
            $products = $woo->get('products', ['per_page' => 100, 'page' => $page]);
            if (empty($products)) {
                break;
            }

            foreach ($products as $p) {
                // WooClient::normaliseResponseBody only json-rounds-trip the outer
                // response, leaving each product as stdClass. Cast to array so the
                // existing array-access pattern works for both shapes.
                $p = (array) $p;
                $type = (string) ($p['type'] ?? 'simple');

                if ($type === 'variation') {
                    $skippedVariation++;
                    continue;
                }
                if (! in_array($type, ['simple', 'variable'], true)) {
                    // grouped / external — out of scope per SyncSupplierCommand precedent
                    $skippedOther++;
                    continue;
                }

                if ($limit > 0 && $simpleSeen >= $limit) {
                    break 2;
                }
                $simpleSeen++;

                $sku = (string) ($p['sku'] ?? '');
                $wooProductId = (int) ($p['id'] ?? 0);

                if ($wooProductId === 0) {
                    $errored++;
                    continue;
                }

                // Quick task 260504-imk — capture stock_quantity only when Woo is
                // actually managing stock. Woo returns stock_quantity=0 even when
                // manage_stock=false (the field is meaningless in that case); we
                // store null to distinguish "we have 0 in stock" from "stock isn't tracked."
                $manageStock = (bool) ($p['manage_stock'] ?? false);
                $stockQty = $manageStock && isset($p['stock_quantity'])
                    ? (int) $p['stock_quantity']
                    : null;

                $payload = [
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => (string) ($p['name'] ?? ''),
                    'type' => $type,
                    'status' => (string) ($p['status'] ?? 'publish'),
                    'stock_status' => (string) ($p['stock_status'] ?? 'instock'),
                    'stock_quantity' => $stockQty,
                    'slug' => (string) ($p['slug'] ?? ''),
                    'short_description' => (string) ($p['short_description'] ?? ''),
                    'long_description' => (string) ($p['description'] ?? ''),
                    'sell_price' => $this->parseDecimal($p['regular_price'] ?? $p['price'] ?? null),
                    'last_synced_at' => now(),
                ];

                if ($withSupplier && $sku !== '' && isset($supplierFeed[$sku])) {
                    $payload['buy_price'] = $this->parseDecimal($supplierFeed[$sku]['price'] ?? null);
                }

                if ($dryRun) {
                    if (Product::where('woo_product_id', $wooProductId)->exists()) {
                        $updated++;
                    } else {
                        $created++;
                    }
                    continue;
                }

                try {
                    $product = Product::updateOrCreate(
                        ['woo_product_id' => $wooProductId],
                        $payload,
                    );
                    if ($product->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (QueryException $e) {
                    $errored++;
                    Log::warning('woo_import.row_failed', [
                        'sku' => $sku,
                        'woo_product_id' => $wooProductId,
                        'error' => $e->getMessage(),
                    ]);
                    ImportIssue::create([
                        'sku' => $sku,
                        'issue_type' => ImportIssue::TYPE_UNKNOWN_SKU,
                        'context' => json_encode([
                            'source' => 'woo:import-products',
                            'woo_product_id' => $wooProductId,
                            'error' => $e->getMessage(),
                        ]),
                    ]);
                }
            }

            $this->line(sprintf(
                '  Page %d: %d products processed (cumulative: %d created, %d updated, %d errored)',
                $page,
                count($products),
                $created,
                $updated,
                $errored,
            ));

            if (count($products) < 100) {
                break;
            }
            $page++;
        } while (true);

        $this->info(sprintf(
            'Done. created=%d updated=%d errored=%d skipped_variation=%d skipped_other=%d',
            $created,
            $updated,
            $errored,
            $skippedVariation,
            $skippedOther,
        ));

        return self::SUCCESS;
    }

    /**
     * Parse Woo's price strings (which can be empty, "12.34", or numeric) into
     * a decimal-safe value or null. Centralised so sell_price and buy_price
     * agree on parsing rules.
     */
    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
