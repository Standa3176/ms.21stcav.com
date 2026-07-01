<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260701-n4y — products:reconcile-stale-woo-ids.
 *
 * One-pass cleanup for products whose Woo product was deleted underneath us.
 * Blast-radius scan (2026-07-01): of 6,237 products with a woo_product_id, 204
 * are STALE (Woo returns no such id) and ALL 204 are status=draft (old `manual`
 * imports). Live shop is fine — every PUBLISHED product still has a valid id.
 * Those stale ids are what made the auto-reprice PUT a price and get a Woo
 * `woocommerce_rest_product_invalid_id` 400 back, failing + retrying the job.
 * PushPriceChangeToWoo now skips non-publish products (so the noise stops at the
 * root) and self-heals invalid ids on published products; this command clears
 * the EXISTING backlog in a single sweep.
 *
 * Logic: pull every Product with a positive woo_product_id, chunk (default 100),
 * ask Woo which of those ids still exist via
 * GET products?include=<csv>&per_page=100&_fields=id&status=any, and NULL the
 * woo_product_id of any product whose id Woo no longer returns — unless
 * --dry-run, which reports counts only.
 *
 * Read path is WooClient::get; the write is a bounded per-stale-product update.
 * Prints total-with-id, stale count, and a by-status breakdown of the stale set.
 *
 * Operator entry points:
 *   php artisan products:reconcile-stale-woo-ids --dry-run   (preview — no writes)
 *   php artisan products:reconcile-stale-woo-ids             (LIVE — null stale ids)
 */
final class ReconcileStaleWooIdsCommand extends BaseCommand
{
    protected $signature = 'products:reconcile-stale-woo-ids
        {--dry-run : Report what would change without writing}
        {--chunk=100 : Woo include batch size (max 100)}';

    protected $description = 'Batch-check every product woo_product_id against Woo and NULL the stale ones (Woo returns no such id). --dry-run reports counts only.';

    public function __construct(
        private readonly WooClient $woo,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, min(100, (int) $this->option('chunk')));

        $this->info('products:reconcile-stale-woo-ids — '.($dryRun ? 'DRY-RUN' : 'LIVE'));

        // Every product currently claiming a Woo id (>0). id + woo_product_id +
        // status only — status feeds the by-status breakdown of the stale set.
        $withId = Product::query()
            ->whereNotNull('woo_product_id')
            ->where('woo_product_id', '>', 0)
            ->get(['id', 'woo_product_id', 'status']);

        $totalWithId = $withId->count();
        $this->info("Products with a woo_product_id: {$totalWithId}");

        if ($totalWithId === 0) {
            $this->info('Nothing to reconcile.');

            return SymfonyCommand::SUCCESS;
        }

        /** @var array<int, Product> $staleProducts */
        $staleProducts = [];

        foreach ($withId->chunk($chunk) as $batch) {
            $ids = $batch->pluck('woo_product_id')->map(fn ($v): int => (int) $v)->all();

            $response = $this->woo->get('products', [
                'include' => implode(',', $ids),
                'per_page' => 100,
                '_fields' => 'id',
                'status' => 'any',
            ]);

            // Collect the ids Woo still knows about.
            $returned = [];
            foreach ($response as $row) {
                $rowId = is_array($row) ? ($row['id'] ?? null) : ($row->id ?? null);
                if ($rowId !== null) {
                    $returned[(int) $rowId] = true;
                }
            }

            foreach ($batch as $product) {
                if (! isset($returned[(int) $product->woo_product_id])) {
                    $staleProducts[] = $product;
                }
            }
        }

        $staleCount = count($staleProducts);

        // By-status breakdown of the stale set.
        $byStatus = [];
        foreach ($staleProducts as $product) {
            $status = (string) ($product->status ?? 'unknown');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        if (! $dryRun) {
            foreach ($staleProducts as $product) {
                $product->forceFill(['woo_product_id' => null])->saveQuietly();
            }
        }

        $this->info(str_repeat('-', 60));
        $this->info(sprintf(
            '%s total_with_id=%d stale=%d',
            $dryRun ? 'Dry-run (no writes):' : 'Done. nulled',
            $totalWithId,
            $staleCount,
        ));

        if ($byStatus !== []) {
            ksort($byStatus);
            foreach ($byStatus as $status => $count) {
                $this->line("  stale by status — {$status}: {$count}");
            }
        }

        return SymfonyCommand::SUCCESS;
    }
}
