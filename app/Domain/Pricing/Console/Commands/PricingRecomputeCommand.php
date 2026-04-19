<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Pricing\Jobs\RecomputePriceJob;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Phase 3 Plan 04 Task 2 — operator CLI for catalogue-wide price recompute.
 *
 * Mirrors the Phase 2 D-04 dry-run-default precedent set by SyncSupplierCommand:
 *   - `pricing:recompute --all`                  → DRY-RUN (D-12 default)
 *   - `pricing:recompute --all --live`           → LIVE writes + events
 *   - `pricing:recompute --all --dry-run`        → explicit DRY-RUN (== default)
 *   - `pricing:recompute --all --live --dry-run` → error: mutually exclusive
 *   - `pricing:recompute`                        → error: pick a scope flag
 *   - `pricing:recompute --only=S1,S2`           → DRY-RUN scoped to listed SKUs
 *   - `pricing:recompute --brand=42 --live`      → LIVE writes for brand 42
 *   - `pricing:recompute --category=10 --live`   → LIVE writes for category 10
 *
 * Dispatches a Bus::batch of `RecomputePriceJob` instances on the `sync-bulk`
 * queue (Phase 1 D-09 + Pitfall 8 segregation). Each job is ShouldBeUnique
 * with a 5-min window so concurrent batches cannot race the same SKU.
 *
 * Extends BaseCommand — correlation_id is threaded onto Context at handle()
 * time and forwarded into every dispatched job so audit_log / integration_events
 * / ProductPriceChanged emissions all join on the same CID.
 *
 * IMPORTANT: --live does NOT bypass WOO_WRITE_ENABLED. It opts in to writing
 * products.sell_price + emitting ProductPriceChanged. The downstream Woo PUT
 * remains gated by Phase 1 D-08's env flag; the command banner reminds the
 * operator of this.
 */
final class PricingRecomputeCommand extends BaseCommand
{
    protected $signature = 'pricing:recompute
        {--all : Recompute the full catalogue}
        {--only= : CSV list of SKUs to recompute (scope filter)}
        {--brand= : Limit to a single brand_id (scope filter)}
        {--category= : Limit to a single category_id (scope filter)}
        {--live : Persist writes + emit ProductPriceChanged (default is DRY-RUN per D-12)}
        {--dry-run : Explicit dry-run flag (== default; error if combined with --live)}';

    protected $description = 'Recompute final prices for a scope of products (dry-run by default, --live for real writes).';

    protected function perform(): int
    {
        // D-12 mutual-exclusion with --live
        if ($this->option('live') && $this->option('dry-run')) {
            $this->error('Error: --live and --dry-run are mutually exclusive (D-12).');

            return SymfonyCommand::INVALID;  // exit 2
        }

        $only = $this->option('only');
        $brand = $this->option('brand');
        $category = $this->option('category');
        $all = (bool) $this->option('all');

        // Scope flags are mutually exclusive (simplification for v1 — a
        // union-of-filters design can resurface later).
        $specificScopes = array_filter([
            'only' => $only !== null && $only !== '',
            'brand' => $brand !== null && $brand !== '',
            'category' => $category !== null && $category !== '',
        ]);

        if (count($specificScopes) > 1) {
            $this->error('Error: --only, --brand, --category are mutually exclusive scopes.');

            return SymfonyCommand::INVALID;
        }

        if (count($specificScopes) === 0 && ! $all) {
            $this->error('Error: One of --all, --only=SKU,... , --brand=ID, --category=ID is required.');

            return SymfonyCommand::INVALID;
        }

        $persist = (bool) $this->option('live');
        $mode = $persist ? 'LIVE' : 'DRY-RUN';

        $this->info("Pricing recompute starting — mode: {$mode}");

        if ($persist) {
            $this->warn('LIVE mode will write products.sell_price and emit ProductPriceChanged per diff.');
            $this->line('  Note: the downstream Woo push remains gated by WOO_WRITE_ENABLED (Phase 1 D-08).');
        } else {
            $this->line('DRY-RUN: no sell_price writes, no ProductPriceChanged events. ImportIssue rows WILL still be written for zero-price products (data-quality fact).');
        }

        $correlationId = (string) (Context::get('correlation_id') ?? '');

        $jobs = [];
        $this->buildJobs($only, $brand, $category, $correlationId, $persist, $jobs);

        $total = count($jobs);

        if ($total === 0) {
            $this->warn('No products matched scope; nothing to dispatch.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Dispatching {$total} RecomputePriceJob(s) onto sync-bulk queue…");

        $batch = Bus::batch($jobs)
            ->name('pricing:recompute '.strtolower($mode))
            ->onQueue('sync-bulk')
            ->allowFailures()
            ->dispatch();

        $this->info("Batch dispatched — id={$batch->id}. Track progress in Horizon.");
        $this->line("  processed: {$total}");
        $this->line("  mode: {$mode}");
        $this->line('  correlation_id: '.($correlationId !== '' ? $correlationId : '(none)'));
        $this->line('  See /horizon for real-time progress.');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Build the job list by walking the scoped product query in chunks.
     *
     * Parent products yield one job; variable products yield one job per
     * variant (the parent's own job is still emitted so data-quality
     * guardrails fire consistently even when all variants carry their
     * own buy_price).
     *
     * @param  array<int, RecomputePriceJob>  $jobs
     */
    private function buildJobs(
        ?string $only,
        ?string $brand,
        ?string $category,
        string $correlationId,
        bool $persist,
        array &$jobs,
    ): void {
        $query = Product::query()->with('variants');

        if ($only !== null && $only !== '') {
            $skus = array_values(array_filter(array_map('trim', explode(',', $only))));
            $query->whereIn('sku', $skus);
        } elseif ($brand !== null && $brand !== '') {
            $query->where('brand_id', (int) $brand);
        } elseif ($category !== null && $category !== '') {
            $query->where('category_id', (int) $category);
        }

        $query->chunkById(500, function ($chunk) use (&$jobs, $correlationId, $persist): void {
            foreach ($chunk as $product) {
                $jobs[] = new RecomputePriceJob(
                    wooProductId: (int) $product->woo_product_id,
                    wooVariationId: null,
                    sku: (string) ($product->sku ?? ''),
                    correlationId: $correlationId,
                    persist: $persist,
                );

                foreach ($product->variants as $variant) {
                    $jobs[] = new RecomputePriceJob(
                        wooProductId: (int) $product->woo_product_id,
                        wooVariationId: (int) $variant->woo_variation_id,
                        sku: (string) ($variant->sku ?? ''),
                        correlationId: $correlationId,
                        persist: $persist,
                    );
                }
            }
        });
    }
}
