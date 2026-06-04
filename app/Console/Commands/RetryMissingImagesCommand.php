<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Finds local Products with empty gallery_image_urls and re-runs
 * products:source-images on them, optionally chained with products:resync-to-woo.
 *
 * Built for the recurring "Manhattan/<brand> retry" case where the original
 * source-images run hit transient Anthropic vision API 4xx errors and left
 * a clump of products with no images. With --brand=Manhattan it scopes to
 * products whose brand_id matches that Woo brand term; without --brand it
 * scans the full catalogue.
 *
 *   php artisan products:retry-missing-images --brand=Manhattan --dry-run
 *   php artisan products:retry-missing-images --brand=Manhattan
 *   php artisan products:retry-missing-images --brand=Manhattan --resync
 *   php artisan products:retry-missing-images --limit=50
 */
final class RetryMissingImagesCommand extends BaseCommand
{
    protected $signature = 'products:retry-missing-images
        {--brand= : Brand name (case-insensitive). When set, only products with this brand_id are retried.}
        {--days= : Only products created in the last N days.}
        {--status= : Comma-separated auto_create_status values (e.g. draft,pending_review). Default: all non-null.}
        {--limit=0 : Max products this run (0 = unbounded).}
        {--all : Include legacy WC-migration products (auto_create_status IS NULL). DANGER: legacy products typically have images on Woo already; re-sourcing creates duplicates and burns Claude spend. Default behavior is auto-created products only.}
        {--resync : Also run products:resync-to-woo on the retried SKUs afterwards (push new images live).}
        {--dry-run : List the SKUs that would be retried + show status/age breakdown; do not call source-images.}';

    protected $description = 'Re-run products:source-images on products that have empty gallery_image_urls.';

    public function __construct(private readonly TaxonomyResolver $taxonomy)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $brandName = trim((string) ($this->option('brand') ?? ''));
        $days = (int) ($this->option('days') ?? 0);
        $statusFilter = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('status') ?? '')))));
        $limit = max(0, (int) $this->option('limit'));
        $includeAll = (bool) $this->option('all');
        $resync = (bool) $this->option('resync');
        $dryRun = (bool) $this->option('dry-run');

        $query = Product::query()
            ->where(function ($q): void {
                $q->whereNull('gallery_image_urls')
                    ->orWhereRaw('JSON_LENGTH(gallery_image_urls) = 0');
            });

        if (! $includeAll) {
            $query->whereNotNull('auto_create_status');
            $this->info('Scoping to auto-created products only (auto_create_status IS NOT NULL). Pass --all to include legacy WC-migration products.');
        } else {
            $this->warn('--all is set: legacy WC-migration products will be included. These typically have Woo images already.');
        }

        if ($statusFilter !== []) {
            $query->whereIn('auto_create_status', $statusFilter);
            $this->info('Status filter: '.implode(', ', $statusFilter));
        }

        if ($days > 0) {
            $since = now()->subDays($days);
            $this->info("Filtering to products created since {$since->toDateTimeString()} ({$days} days).");
            $query->where('created_at', '>=', $since);
        }

        if ($brandName !== '') {
            $brandId = $this->resolveBrandId($brandName);
            if ($brandId === null) {
                $this->error("Brand '{$brandName}' not found in Woo brand taxonomy. Aborting.");

                return SymfonyCommand::FAILURE;
            }
            $this->info("Resolved brand '{$brandName}' → brand_id={$brandId}");
            $query->where('brand_id', $brandId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $skus = $query->orderBy('id')->pluck('sku')->filter()->values()->all();
        $count = count($skus);

        if ($count === 0) {
            $this->info('No products with missing images match the filter.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Found {$count} product(s) with missing images.");
        foreach (array_slice($skus, 0, 20) as $sku) {
            $this->line("  - {$sku}");
        }
        if ($count > 20) {
            $this->line('  ... and '.($count - 20).' more.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Breakdown by auto_create_status:');
            $statusCounts = (clone $query)
                ->selectRaw('auto_create_status, COUNT(*) as n')
                ->groupBy('auto_create_status')
                ->orderByDesc('n')
                ->get();
            foreach ($statusCounts as $row) {
                $this->line('  '.str_pad((string) $row->n, 6, ' ', STR_PAD_LEFT).'  '.($row->auto_create_status ?? '(null)'));
            }

            $this->newLine();
            $this->info('Breakdown by created_at age:');
            $ageCounts = (clone $query)
                ->selectRaw("CASE
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)  THEN '0-1d'
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN '1-7d'
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN '7-30d'
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN '30-90d'
                    ELSE '90d+'
                END as bucket, COUNT(*) as n")
                ->groupBy('bucket')
                ->orderByRaw("FIELD(bucket, '0-1d','1-7d','7-30d','30-90d','90d+')")
                ->get();
            foreach ($ageCounts as $row) {
                $this->line('  '.str_pad((string) $row->n, 6, ' ', STR_PAD_LEFT).'  '.$row->bucket);
            }

            $costPence = $count * 10;
            $this->newLine();
            $this->info(sprintf(
                'Estimated Claude spend if you run this set: ~%dp (~£%s) at 10p/SKU.',
                $costPence, number_format($costPence / 100, 2),
            ));
            $this->info('Dry-run — exiting without calling source-images.');

            return SymfonyCommand::SUCCESS;
        }

        $skusCsv = implode(',', $skus);

        $this->newLine();
        $this->info('==> products:source-images');
        $imageExit = Artisan::call('products:source-images', ['--skus' => $skusCsv]);
        $this->line(Artisan::output());

        if ($imageExit !== 0) {
            $this->error('source-images exited non-zero — skipping resync.');

            return SymfonyCommand::FAILURE;
        }

        if ($resync) {
            $this->newLine();
            $this->info('==> products:resync-to-woo');
            Artisan::call('products:resync-to-woo', ['--skus' => $skusCsv]);
            $this->line(Artisan::output());
        }

        $this->newLine();
        $this->info("Done. {$count} product(s) processed.".($resync ? ' Images pushed to Woo.' : ' Run with --resync to push to Woo.'));

        return SymfonyCommand::SUCCESS;
    }

    private function resolveBrandId(string $brandName): ?int
    {
        $needle = mb_strtolower($brandName);
        foreach ($this->taxonomy->allBrands() as $brand) {
            if (mb_strtolower((string) ($brand['name'] ?? '')) === $needle) {
                return (int) $brand['id'];
            }
        }

        return null;
    }
}
