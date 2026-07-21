<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SourceabilityClassifier;
use App\Domain\Sync\Services\SupplierFeedReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260719-mgp — READ-ONLY sourceability matching-gap probe.
 *
 * ~1,830 on-Woo products aren't matched to supplier_sku_cache (which holds the
 * EXACT lowercased-trimmed feed keys). Before any cull or matcher rewrite we
 * need the real split of WHY. This command samples the "not sourceable" set and
 * classifies each product against the remote supplier feed:
 *
 *   (a) matching_gap             — supplier carries it under a different SKU
 *                                  format (fixable via the matcher)
 *   (b) brand_in_feed_item_absent — manufacturer is in the feed but this exact
 *                                  item isn't (likely discontinued / lead-time)
 *   (c) not_in_feed              — manufacturer absent from the feed (genuinely
 *                                  absent — a business cull candidate)
 *   (d) no_manufacturer          — no brand/manufacturer to key on
 *
 * SAFETY (post-incident): READ-ONLY. No writes anywhere, no Woo calls, no status
 * changes, no matcher changes. It queries the REMOTE supplier feed DB (the
 * supplier_db VPS — a separate box from the shop+app server) via the injectable
 * {@see SupplierFeedReader} seam, and it SAMPLES + BOUNDS every remote read:
 * default --limit 150, per-manufacturer fetches deduped within the run, hard row
 * cap per manufacturer. Total remote queries ≈ distinct manufacturers in the
 * sample.
 *
 * Manufacturer resolution (no Woo call): brand_id → name via the READ-ONLY
 * cached Woo taxonomy (taxonomy.brands, populated by the auto-create schedule),
 * falling back to the leading token of the product name. Products that yield no
 * manufacturer land in bucket (d).
 *
 * Usage:
 *   php artisan supplier:probe-sourceability-gap                       (150, all statuses)
 *   php artisan supplier:probe-sourceability-gap --limit=400           (tighter confidence)
 *   php artisan supplier:probe-sourceability-gap --status=publish      (published only)
 */
final class ProbeSourceabilityGapCommand extends BaseCommand
{
    /** Hard cap on rows fetched per manufacturer (bounded remote read). */
    private const PER_MANUFACTURER_ROW_CAP = 5000;

    /** Examples shown per bucket in the output. */
    private const EXAMPLES_PER_BUCKET = 5;

    protected $signature = 'supplier:probe-sourceability-gap
        {--limit=150 : Sample size — on-Woo products NOT in supplier_sku_cache to classify}
        {--status=all : Woo status filter: publish|pending|all}';

    protected $description = 'READ-ONLY diagnostic: classify why on-Woo products are not matched to supplier_sku_cache (matching gap vs discontinued vs absent).';

    public function __construct(
        private readonly SupplierFeedReader $feed,
        private readonly SourceabilityClassifier $classifier,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $status = strtolower((string) $this->option('status'));
        if (! in_array($status, ['publish', 'pending', 'all'], true)) {
            $this->error("Invalid --status '{$status}'. Use publish|pending|all.");

            return SymfonyCommand::FAILURE;
        }

        $this->info('supplier:probe-sourceability-gap — READ-ONLY (no writes, no Woo calls)');
        $this->line("  sample limit={$limit}  status={$status}");

        // ── Brand-id → name map (READ-ONLY cache read; never triggers a Woo call) ──
        $brandNames = $this->brandIdToNameMap();
        $this->line('  brand-id→name map: '.count($brandNames).' entries '
            .(count($brandNames) === 0 ? '(cache cold — falling back to product-name tokens)' : '(from cached Woo taxonomy)'));

        // ── Sample the "not sourceable" set ──
        $products = $this->sampleNotSourceable($status, $limit);
        $sampled = $products->count();
        $this->info("Sampled {$sampled} on-Woo products not in supplier_sku_cache.");

        // ── Classify ──
        /** @var array<string, int> $counts */
        $counts = [
            SourceabilityClassifier::MATCHING_GAP => 0,
            SourceabilityClassifier::BRAND_IN_FEED_ITEM_ABSENT => 0,
            SourceabilityClassifier::NOT_IN_FEED => 0,
            SourceabilityClassifier::NO_MANUFACTURER => 0,
        ];
        /** @var array<string, array<int, array{sku:string,name:string,manufacturer:string,matched:string}>> $examples */
        $examples = array_fill_keys(array_keys($counts), []);

        /** @var array<string, array<int, array{mpn:string, suppliersku:string}>> $feedCache */
        $feedCache = [];
        $manufacturersFetched = [];
        $cappedManufacturers = [];

        foreach ($products as $product) {
            $sku = (string) $product->sku;
            $manufacturer = $this->resolveManufacturer($product, $brandNames);

            if ($manufacturer === null) {
                $result = $this->classifier->classify(null, $sku, []);
            } else {
                $key = mb_strtolower(trim($manufacturer));
                if (! array_key_exists($key, $feedCache)) {
                    $rows = $this->feed->rowsForManufacturer($manufacturer, self::PER_MANUFACTURER_ROW_CAP);
                    $feedCache[$key] = $rows;
                    $manufacturersFetched[$key] = true;
                    if (count($rows) >= self::PER_MANUFACTURER_ROW_CAP) {
                        $cappedManufacturers[$key] = true;
                    }
                }
                $result = $this->classifier->classify($manufacturer, $sku, $feedCache[$key]);
            }

            $bucket = $result['bucket'];
            $counts[$bucket]++;
            if (count($examples[$bucket]) < self::EXAMPLES_PER_BUCKET) {
                $examples[$bucket][] = [
                    'sku' => $sku,
                    'name' => (string) $product->name,
                    'manufacturer' => $manufacturer ?? '—',
                    'matched' => (string) ($result['matched_feed_key'] ?? '—'),
                ];
            }
        }

        $this->line('  Remote feed queries issued: '.count($manufacturersFetched).' (one per distinct manufacturer, deduped).');
        if ($cappedManufacturers !== []) {
            $this->warn('  Row cap ('.self::PER_MANUFACTURER_ROW_CAP.') hit for manufacturer(s): '
                .implode(', ', array_keys($cappedManufacturers)).' — those buckets are lower bounds.');
        }

        $this->renderSummary($counts, $sampled);
        $this->renderExamples($examples);
        $this->renderInterpretation();

        return SymfonyCommand::SUCCESS;
    }

    /**
     * On-Woo products whose lowercased-trimmed SKU is NOT in supplier_sku_cache.
     * Random sample for representativeness. Uses whereRaw (query-builder method,
     * NOT the DB facade) to keep the Sync layer clear of -WpDirectDb; LOWER/TRIM
     * are portable across SQLite (tests) + MySQL (prod).
     *
     * @return Collection<int, Product>
     */
    private function sampleNotSourceable(string $status, int $limit)
    {
        return Product::query()
            ->whereNotNull('woo_product_id')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->when($status !== 'all', fn (Builder $q): Builder => $q->where('status', $status))
            ->whereNotExists(function ($q): void {
                $q->from('supplier_sku_cache')
                    ->whereRaw('supplier_sku_cache.sku = LOWER(TRIM(products.sku))');
            })
            ->inRandomOrder()
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'brand_id', 'status']);
    }

    /**
     * Resolve a product's manufacturer with NO Woo call:
     *   1. brand_id → name via the read-only cached Woo taxonomy, else
     *   2. the leading whitespace-delimited token of the product name, else
     *   3. null → bucket (d).
     *
     * @param  array<int, string>  $brandNames  brand_id => brand name
     */
    private function resolveManufacturer(Product $product, array $brandNames): ?string
    {
        $brandId = $product->brand_id === null ? null : (int) $product->brand_id;
        if ($brandId !== null && isset($brandNames[$brandId]) && trim($brandNames[$brandId]) !== '') {
            return trim($brandNames[$brandId]);
        }

        $name = trim((string) $product->name);
        if ($name === '') {
            return null;
        }

        $token = preg_split('/\s+/', $name)[0] ?? '';

        return $token === '' ? null : $token;
    }

    /**
     * Build brand_id → name from the cached Woo taxonomy (taxonomy.brands).
     * READ-ONLY: Cache::get never fetches from Woo — if the cache is cold we
     * return an empty map and fall back to name tokens. Shape mirrors
     * TaxonomyResolver::allBrands(): [['id'=>int,'name'=>string], ...].
     *
     * @return array<int, string>
     */
    private function brandIdToNameMap(): array
    {
        $terms = Cache::get('taxonomy.brands');
        if (! is_array($terms)) {
            return [];
        }

        /** @var array<int, string> $map */
        $map = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $id = isset($term['id']) ? (int) $term['id'] : 0;
            $name = isset($term['name']) ? (string) $term['name'] : '';
            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderSummary(array $counts, int $sampled): void
    {
        $this->newLine();
        $this->info('── Sourceability split over the sample ──');

        if ($sampled === 0) {
            $this->line('  (empty sample — nothing to classify)');

            return;
        }

        $labels = [
            SourceabilityClassifier::MATCHING_GAP => '(a) matching_gap',
            SourceabilityClassifier::BRAND_IN_FEED_ITEM_ABSENT => '(b) brand_in_feed_item_absent',
            SourceabilityClassifier::NOT_IN_FEED => '(c) not_in_feed',
            SourceabilityClassifier::NO_MANUFACTURER => '(d) no_manufacturer',
        ];

        $rows = [];
        foreach ($labels as $bucket => $label) {
            $count = $counts[$bucket];
            $pct = $sampled > 0 ? round(($count / $sampled) * 100, 1) : 0.0;
            $rows[] = [$label, $bucket, $count, $pct.'%'];
        }

        $this->table(['Bucket', 'key', 'count', '% of sample'], $rows);
    }

    /**
     * @param  array<string, array<int, array{sku:string,name:string,manufacturer:string,matched:string}>>  $examples
     */
    private function renderExamples(array $examples): void
    {
        foreach ($examples as $bucket => $rows) {
            if ($rows === []) {
                continue;
            }
            $this->newLine();
            $this->line("Examples — {$bucket}:");
            $this->table(
                ['sku', 'name', 'manufacturer', 'matched-feed-key'],
                array_map(static fn (array $r): array => [
                    $r['sku'],
                    mb_strimwidth($r['name'], 0, 40, '…'),
                    mb_strimwidth($r['manufacturer'], 0, 24, '…'),
                    $r['matched'],
                ], $rows),
            );
        }
    }

    private function renderInterpretation(): void
    {
        // ASCII-only on purpose: these lines are asserted in tests and read on a
        // Windows/CWP console, where multibyte arrows render (and capture) badly.
        $this->newLine();
        $this->info('-- Interpretation --');
        // Standalone takeaway line: deliberately contains NO bucket key, so it reads
        // as the headline and is independently assertable.
        $this->info('  Takeaway: bucket (a) is fixable by a smarter matcher; bucket (c) is a genuine cull candidate.');
        $this->info('  (a) matching_gap             -> fixable: supplier carries it under a different SKU format; a smarter matcher recovers these.');
        $this->info('  (b) brand_in_feed_item_absent -> the brand is in the feed but this exact part is not - likely discontinued / lead-time; review, do not blindly cull.');
        $this->info('  (c) not_in_feed              -> genuinely absent: no supplier lists this brand - a business cull candidate.');
        $this->info('  (d) no_manufacturer          -> could not key on a brand; excluded from the feed comparison (resolve brand first).');
    }
}
