<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Concerns\BuildsWooStockPayload;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260701-pmr — products:backfill-woo-stock.
 *
 * One-time (re-runnable) backfill of the WooCommerce stock keys onto EXISTING
 * app-created products. New auto-published products already carry
 * manage_stock=true + stock_quantity + stock_status at publish (260701-opg);
 * but the ~600 products published BEFORE that fix have manage_stock=false on
 * Woo, so WooCommerce ignores their quantity and shows no stock line. The
 * scheduled cutover:auto-sync pushes stock_quantity but NOT manage_stock, so
 * those products never display stock.
 *
 * This command PUTs manage_stock=true + current stock_quantity + stock_status
 * (via the shared BuildsWooStockPayload trait) to Woo for the existing
 * app-created published products. Pure Woo writes — no Claude spend. After it
 * runs, cutover:auto-sync keeps the quantities fresh.
 *
 * Scope: by default products where auto_create_status='published' AND
 * woo_product_id > 0. --skus=CSV overrides the scope to those exact SKUs.
 * --limit=N caps the run (0 = all). --dry-run prints what would be pushed and
 * writes nothing.
 *
 * Per product it PUTs products/{woo_product_id} with the stock payload. On a
 * WooCommerce woocommerce_rest_product_invalid_id error it NULLs the stale
 * woo_product_id (saveQuietly) + logs + counts as skipped_stale, and CONTINUES
 * (never aborts the batch); any other error is counted + logged and the loop
 * continues. Idempotent + safe to re-run.
 *
 * NOT scheduled (one-time; cutover:auto-sync handles ongoing freshness).
 *
 * Operator entry points:
 *   php artisan products:backfill-woo-stock --dry-run   (preview — no writes)
 *   php artisan products:backfill-woo-stock             (LIVE — ~600 Woo PUTs)
 */
final class BackfillWooStockCommand extends BaseCommand
{
    use BuildsWooStockPayload;

    protected $signature = 'products:backfill-woo-stock
        {--skus= : Comma-separated SKUs to target instead of the default app-created-published set}
        {--limit=0 : Max products this run (0 = all)}
        {--dry-run : Print what would be pushed; write nothing}';

    protected $description = 'One-time backfill: PUT manage_stock+quantity+status to Woo for existing app-created published products so they show a stock line. --dry-run previews.';

    public function __construct(
        private readonly WooClient $woo,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $skus = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        $dryRun = (bool) $this->option('dry-run');

        $this->info('products:backfill-woo-stock — '.($dryRun ? 'DRY-RUN' : 'LIVE'));

        $q = Product::query()
            ->whereNotNull('woo_product_id')
            ->where('woo_product_id', '>', 0);

        $q = $skus !== []
            ? $q->whereIn('sku', $skus)
            : $q->where('auto_create_status', 'published');

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $q->limit($limit);
        }

        $pushed = 0;
        $wouldPush = 0;
        $skippedStale = 0;
        $errors = 0;

        foreach ($q->cursor() as $product) {
            $payload = $this->wooStockPayload($product);

            if ($dryRun) {
                $wouldPush++;
                if ($wouldPush <= 20) {
                    $this->line(sprintf(
                        '  would push %s → qty=%d %s',
                        $product->sku,
                        $payload['stock_quantity'],
                        $payload['stock_status'],
                    ));
                }

                continue;
            }

            try {
                $this->woo->put("products/{$product->woo_product_id}", $payload);
                $pushed++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'woocommerce_rest_product_invalid_id')) {
                    Log::warning('backfill_woo_stock.stale_id_cleared', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'woo_product_id' => $product->woo_product_id,
                    ]);
                    $product->forceFill(['woo_product_id' => null])->saveQuietly();
                    $skippedStale++;
                } else {
                    Log::warning('backfill_woo_stock.push_failed', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }
        }

        $this->info(str_repeat('-', 60));
        $this->info($dryRun
            ? sprintf('Dry-run (no writes): would_push=%d', $wouldPush)
            : sprintf('Done. pushed=%d skipped_stale=%d errors=%d', $pushed, $skippedStale, $errors));

        return SymfonyCommand::SUCCESS;
    }
}
