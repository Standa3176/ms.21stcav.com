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
        {--limit=0 : Max products this run (0 = unbounded).}
        {--resync : Also run products:resync-to-woo on the retried SKUs afterwards (push new images live).}
        {--dry-run : List the SKUs that would be retried; do not call source-images.}';

    protected $description = 'Re-run products:source-images on products that have empty gallery_image_urls.';

    public function __construct(private readonly TaxonomyResolver $taxonomy)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $brandName = trim((string) ($this->option('brand') ?? ''));
        $limit = max(0, (int) $this->option('limit'));
        $resync = (bool) $this->option('resync');
        $dryRun = (bool) $this->option('dry-run');

        $query = Product::query()
            ->where(function ($q): void {
                $q->whereNull('gallery_image_urls')
                    ->orWhereRaw('JSON_LENGTH(gallery_image_urls) = 0');
            });

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
