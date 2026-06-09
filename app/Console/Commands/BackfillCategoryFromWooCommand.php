<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260607-v5g — products:backfill-category-from-woo.
 *
 * Root cause: the legacy `woo:import-products` command (quick 260504-d7v)
 * ingested SKU/name/price/stock from WooCommerce but skipped the `categories`
 * array on each product object. The 260607-t6w audit confirmed the gap at
 * 3,244 live MS products with NULL `category_id` + NULL `category_ids`,
 * even though Woo holds the authoritative taxonomy on its side.
 *
 * This command does the minimum: pull `categories` for the candidate set
 * via Woo REST `GET /wp-json/wc/v3/products?include=...&orderby=include`,
 * and write back two columns (`category_id` = `categories[0].id`, and
 * `category_ids` = JSON list of all category IDs). Free, deterministic,
 * no Claude credit spent.
 *
 * Run guidance: Run manually post-deploy + after any future Woo-side
 * category re-mapping. NOT scheduled because Woo→MS category drift is
 * rare once the initial backfill lands. Operator-triggered only.
 *
 * `--resync` chain is mostly a no-op for category changes (categories
 * flow Woo→MS, not MS→Woo). Present for symmetry with
 * BackfillMerchantFeedCommand + in case other product fields drift on
 * the touched SKUs.
 *
 * Outcome bucket legend:
 *   updated                      → wrote category_id + category_ids
 *   no_woo_categories            → Woo product exists but `categories` array empty
 *   no_woo_product_id            → local row has NULL woo_product_id (filtered by query)
 *   woo_not_found                → Woo response omitted the requested ID
 *   error                        → Woo REST call threw (whole chunk counted)
 *   already_populated_excluded   → skipped because local already has category_id
 *
 *   php artisan products:backfill-category-from-woo --dry-run
 *   php artisan products:backfill-category-from-woo --limit=100
 *   php artisan products:backfill-category-from-woo --skus=ABC-123,DEF-456
 *   php artisan products:backfill-category-from-woo --resync
 */
// Not `final` so the Pest feature test can swap the bound WooClient stub
// without subclassing the command itself (mirrors the
// BackfillMerchantFeedCommand testing pattern at 260607-cgd).
class BackfillCategoryFromWooCommand extends BaseCommand
{
    protected $signature = 'products:backfill-category-from-woo
        {--skus= : Comma-separated SKU list; default = all live products with NULL category_id}
        {--limit=0 : Max products this run (0=unbounded)}
        {--chunk=50 : Woo IDs per batch request — Woo per_page max is 100}
        {--dry-run : Print what would change without writing}
        {--resync : Chain products:resync-to-woo on updated SKUs after backfill}
        {--no-confirm : Skip interactive y/N confirmations (use for cron / non-interactive runs)}';

    protected $description = 'Backfill local category_id + category_ids from Woo REST for products where MS local mirror is missing categories (closes 260607-t6w audit gap of 3,244 rows).';

    /**
     * Captured at perform() start so the auth context is preserved across
     * nested Artisan::call() chains. Null for cron / queue runs.
     */
    private ?User $triggeringUser = null;

    public function __construct(private readonly WooClient $woo)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $this->triggeringUser = auth()->user();

        // Parse options.
        $skusFilter = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('skus'))),
            static fn (string $s): bool => $s !== '',
        ));
        $limit = max(0, (int) $this->option('limit'));
        // Clamp chunk to Woo's documented `per_page` ceiling of 100.
        $chunkSize = max(1, min(100, (int) $this->option('chunk')));
        $dryRun = (bool) $this->option('dry-run');
        $resync = (bool) $this->option('resync');
        $noConfirm = (bool) $this->option('no-confirm') || ! $this->input->isInteractive();

        // Build the candidate query.
        //
        // Default path: NULL-category live publish rows that have a Woo ID
        // (the 3,244-row backfill target). When --skus is passed the operator
        // may want to re-pull categories for an already-populated row, so we
        // drop the whereNull('category_id') filter but KEEP status=publish +
        // whereNotNull(woo_product_id).
        if ($skusFilter !== []) {
            $query = Product::query()
                ->where('status', 'publish')
                ->whereNotNull('woo_product_id')
                ->whereIn('sku', $skusFilter);
        } else {
            $query = Product::query()
                ->where('status', 'publish')
                ->whereNotNull('woo_product_id')
                ->whereNull('category_id');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        // Stream candidates via ->cursor() — avoids loading 3,244 hydrated
        // Product models at once (~10MB+ vs ~1MB for the sku+woo_id map).
        // We only need (sku, woo_product_id) for the chunk loop.
        /** @var array<string, int> $candidates  sku => woo_product_id */
        $candidates = [];
        $seen = 0;
        foreach ($query->cursor() as $row) {
            $sku = (string) $row->sku;
            $wooId = (int) ($row->woo_product_id ?? 0);
            if ($sku === '' || $wooId <= 0) {
                continue;
            }
            $candidates[$sku] = $wooId;
            $seen++;
            // Defensive — cursor honours limit() but belt-and-braces.
            if ($limit > 0 && $seen >= $limit) {
                break;
            }
        }

        $count = count($candidates);
        if ($count === 0) {
            $this->info('Backfill: 0 candidate products.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Backfill candidates: {$count} product(s). Chunk size: {$chunkSize}.".($dryRun ? '  (dry-run)' : '  (LIVE)'));

        // Outcome counters. $updated covers both the live "actually wrote N
        // rows" and the dry-run "would have written N rows" cases — the
        // outcome table relabels the row token but the count is the same.
        $updated = 0;
        $noWooCategories = 0;
        $noWooProductId = 0;          // 0 by construction unless --skus oddball
        $wooNotFound = 0;
        $errors = 0;
        $alreadyPopulated = 0;        // 0 by construction unless --skus override

        $updatedSkus = [];
        $sample = [];                 // capped at 20 rows: [sku, primary, count, names, outcome]

        foreach (array_chunk($candidates, $chunkSize, true) as $chunk) {
            $ids = array_values($chunk);
            // Reverse lookup: woo_id => sku. array_flip is safe because each
            // woo_product_id is unique per SKU in our candidate set.
            $skuByWooId = array_flip($chunk);

            try {
                $response = $this->woo->get('products', [
                    'include' => implode(',', $ids),
                    'per_page' => count($ids),
                    'orderby' => 'include',
                ]);
            } catch (\Throwable $e) {
                $errors += count($chunk);
                foreach ($chunk as $sku => $wooId) {
                    if (count($sample) < 20) {
                        $sample[] = [$sku, '(error)', 0, $e->getMessage(), 'error'];
                    }
                }
                $this->warn("  ! chunk failed: {$e->getMessage()} ({$errors} cumulative errors)");

                continue;
            }

            // Build lookup [woo_id => product_row]. Woo SDK returns stdClass for
            // list endpoints — normalise via json round-trip so array access
            // works uniformly. (Prior comment claimed WooClient does this; it
            // doesn't — confirmed live 260609 by audit-stock-divergence dry-run.)
            $lookup = [];
            foreach ($response as $product) {
                if (! is_array($product)) {
                    $product = json_decode(json_encode($product), true);
                }
                if (! is_array($product)) {
                    continue;
                }
                $pid = (int) ($product['id'] ?? 0);
                if ($pid > 0) {
                    $lookup[$pid] = $product;
                }
            }

            foreach ($chunk as $sku => $wooId) {
                if (! isset($lookup[$wooId])) {
                    $wooNotFound++;
                    if (count($sample) < 20) {
                        $sample[] = [$sku, '(not in response)', 0, '', 'woo_not_found'];
                    }

                    continue;
                }

                $cats = $lookup[$wooId]['categories'] ?? [];
                if (! is_array($cats) || $cats === []) {
                    $noWooCategories++;
                    if (count($sample) < 20) {
                        $sample[] = [$sku, '-', 0, '', 'no_woo_categories'];
                    }

                    continue;
                }

                $primary = (int) ($cats[0]['id'] ?? 0);
                $idList = array_values(array_map(
                    static fn ($c): int => (int) ($c['id'] ?? 0),
                    $cats,
                ));
                $names = implode(' / ', array_map(
                    static fn ($c): string => (string) ($c['name'] ?? ''),
                    $cats,
                ));

                if ($dryRun) {
                    $updated++;
                    if (count($sample) < 20) {
                        $sample[] = [$sku, $primary, count($idList), $names, 'would_update'];
                    }

                    continue;
                }

                // Live write — DB::table('products')->update(...) bypasses
                // Eloquent for speed (3,244 rows ≈ 65 chunks of 50). The
                // model has `'category_ids' => 'array'` cast which would
                // auto-json_encode on Product::update(), but DB::table()
                // does NOT — explicit json_encode() is required.
                // See Product model docblock.
                DB::table('products')
                    ->where('sku', $sku)
                    ->update([
                        'category_id' => $primary,
                        'category_ids' => json_encode($idList),
                        'updated_at' => now(),
                    ]);
                $updated++;
                $updatedSkus[] = $sku;

                if (count($sample) < 20) {
                    $sample[] = [$sku, $primary, count($idList), $names, 'updated'];
                }
            }
        }

        // Print outcome table.
        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                [$dryRun ? 'would_update' : 'updated', $updated],
                ['no_woo_categories', $noWooCategories],
                ['woo_not_found', $wooNotFound],
                ['no_woo_product_id', $noWooProductId],
                ['error', $errors],
                ['already_populated_excluded', $alreadyPopulated],
            ],
        );

        $this->newLine();
        $this->info('Sample (first 20):');
        $this->table(
            ['SKU', 'Primary cat', '# cats', 'Names', 'Outcome'],
            $sample,
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run — exiting without writes.');

            return SymfonyCommand::SUCCESS;
        }

        $this->newLine();
        $this->info("Updated {$updated} product(s) with category_id + category_ids.");

        // --resync chain on successfully-updated SKUs only.
        if ($resync && $updatedSkus !== []) {
            $uniq = array_values(array_unique($updatedSkus));
            $resyncCount = count($uniq);
            $this->newLine();
            $this->info("Resync candidates: {$resyncCount} SKU(s) successfully updated this run.");

            $proceed = true;
            if (! $noConfirm) {
                $proceed = $this->confirm("Push {$resyncCount} updated SKU(s) to Woo via products:resync-to-woo?", true);
            }
            if ($proceed) {
                $this->info('==> products:resync-to-woo');
                Artisan::call('products:resync-to-woo', ['--skus' => implode(',', $uniq)]);
                $this->line(Artisan::output());
            } else {
                $this->warn('Resync skipped by operator.');
            }
        } elseif ($resync) {
            $this->info('Resync requested but no SKUs were successfully updated this run.');
        }

        return SymfonyCommand::SUCCESS;
    }
}
