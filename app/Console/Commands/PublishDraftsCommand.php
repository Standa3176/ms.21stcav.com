<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * products:publish-drafts — bulk-publish COMPLETE, never-pushed auto-created
 * drafts to Woo through the SAME PublishProductJob path the review-inbox
 * "Approve" action uses.
 *
 * The Auto-create Health page lists complete auto-created drafts (brand +
 * category present) that were never pushed to Woo — they show
 * auto_create_status=draft and Woo ID "— not pushed —". There was no CLI to
 * publish EXISTING drafts: PublishProductJob was only wired to the suggestions
 * pipeline, and products:resync-to-woo only re-pushes products that already
 * carry a woo_product_id. This command closes that gap.
 *
 * Selection (all conditions required):
 *   - autoCreated()                    (auto_create_status != 'manual')
 *   - woo_product_id IS NULL           (never pushed — resync handles the rest)
 *   - brand_id NOT NULL                (never publish a needs-assignment row)
 *   - category_id NOT NULL             (never publish a taxonomy-incomplete row)
 *   - auto_create_status IN --status   (default: ['draft'])
 *
 * Each match is dispatched via PublishProductJob::dispatchSync so the command
 * reports per-SKU published / shadowed (WOO_WRITE_ENABLED=false) / failed and
 * refreshes woo_product_id inline — mirroring DraftFromSuggestionsCommand's
 * --auto-approve loop. It runs the FULL publish path, so live-stock hydration
 * (260702-pes) and brand linkage all apply.
 *
 * There is intentionally NO completeness-threshold gate here: this is an
 * explicit operator action on drafts they can already see on the health page.
 * We only enforce the two hard "never go live without taxonomy" invariants
 * (brand_id + category_id) and the "never re-push" invariant (woo_product_id).
 *
 *   php artisan products:publish-drafts --dry-run          # preview count + SKUs
 *   php artisan products:publish-drafts                    # publish all complete drafts
 *   php artisan products:publish-drafts --require-images   # only drafts with a gallery
 *   php artisan products:publish-drafts --skus=A,B --limit=10
 *
 * Requires WOO_WRITE_ENABLED=true (prod). With writes off, each product is
 * reported 'shadowed' and NOT marked published (no false-live rows), exactly as
 * DraftFromSuggestionsCommand already handles.
 */
final class PublishDraftsCommand extends BaseCommand
{
    protected $signature = 'products:publish-drafts
        {--status=draft : Comma-separated auto_create_status values eligible to publish (default: draft).}
        {--skus= : Comma-separated explicit SKU list (still filtered to complete + not-pushed).}
        {--require-images : Only publish drafts that already have >=1 gallery image (default: publish regardless — Woo uses its placeholder).}
        {--limit=0 : Max products this run (0 = unbounded).}
        {--dry-run : List matching SKUs + counts; dispatch nothing.}';

    protected $description = 'Bulk-publish complete auto-created drafts (brand+category set, never pushed) to Woo via PublishProductJob.';

    protected function perform(): int
    {
        // 1. Parse options.
        $statuses = $this->parseCsv((string) ($this->option('status') ?? ''));
        if ($statuses === []) {
            $statuses = ['draft'];
        }
        $explicit = $this->parseCsv((string) ($this->option('skus') ?? ''));
        $requireImages = (bool) $this->option('require-images');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // 2. Capture the triggering user id BEFORE any dispatch (auth context can
        //    drift across nested invocations — mirrors RetryMissingImages).
        $userId = (int) (auth()->id() ?? 0);

        // 3. Build the selection query — the four hard invariants + status set.
        $q = Product::query()
            ->autoCreated()                       // auto_create_status != 'manual'
            ->whereNull('woo_product_id')         // never pushed
            ->whereNotNull('brand_id')            // complete taxonomy — never publish a needs-assignment row
            ->whereNotNull('category_id')
            ->whereIn('auto_create_status', $statuses);

        if ($explicit !== []) {
            $q->whereIn('sku', $explicit);
        }

        if ($requireImages) {
            // Driver-aware, mirrors AutoCreateHealthPage::emptyImagesExpr but the
            // NON-empty case. Driver name is not user input → no SQLi.
            $expr = DB::connection()->getDriverName() === 'sqlite'
                ? 'json_array_length(gallery_image_urls) > 0'
                : 'JSON_LENGTH(gallery_image_urls) > 0';
            $q->whereNotNull('gallery_image_urls')->whereRaw($expr);
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        /** @var array<int, Product> $products */
        $products = $q->orderBy('id')->get()->all();

        if ($products === []) {
            $this->info('No complete not-pushed drafts match.');

            return SymfonyCommand::SUCCESS;
        }

        // 4. --dry-run — list matches + total, dispatch nothing.
        if ($dryRun) {
            $rows = array_map(static function (Product $p): array {
                $gallery = is_array($p->gallery_image_urls) ? $p->gallery_image_urls : [];

                return [
                    (string) $p->sku,
                    (string) $p->auto_create_status,
                    (string) count($gallery),
                    (string) ($p->brand_id ?? ''),
                    (string) ($p->category_id ?? ''),
                ];
            }, $products);

            $this->table(['SKU', 'Status', 'Images', 'Brand ID', 'Category ID'], $rows);
            $this->info('Would publish '.count($products).' product(s).');

            return SymfonyCommand::SUCCESS;
        }

        // 5. Live — dispatch PublishProductJob per product (mirror --auto-approve).
        $scanned = count($products);
        $published = 0;
        $shadowed = 0;
        $failed = 0;

        $this->info("Publishing {$scanned} complete not-pushed draft(s) via PublishProductJob...");
        foreach ($products as $product) {
            try {
                // dispatchSync runs the job inline so we can capture the back-fill
                // of woo_product_id without waiting on Horizon.
                PublishProductJob::dispatchSync((int) $product->id, $userId);
                $product->refresh();
                if ((int) ($product->woo_product_id ?? 0) > 0) {
                    $published++;
                    $this->line("  ✓ {$product->sku} → Woo #{$product->woo_product_id}");
                } else {
                    // No woo_product_id back-filled = shadow mode (WOO_WRITE_ENABLED=false)
                    // or some other no-op path. Stays a draft; NOT marked published.
                    $shadowed++;
                    $this->warn("  ⊘ {$product->sku}: no Woo id (shadow mode?) — not marked published");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  ✗ {$product->sku}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. scanned: %d, published: %d, shadowed: %d, failed: %d.',
            $scanned, $published, $shadowed, $failed,
        ));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Parse a comma-separated option into a trimmed, blank-dropped, deduped list.
     * Case is PRESERVED — SKUs + status values are compared verbatim downstream.
     *
     * @return array<int, string>
     */
    private function parseCsv(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        );

        return array_values(array_unique($parts));
    }
}
