<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Concerns\ResolvesWooBrandKey;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260702-h50 — products:refresh-brands-to-add.
 *
 * Piece 1 of the operator-confirmed "Brands to Add" workflow (Piece 2 = the
 * admin page + Suggestions Brand/on-Woo filters + one-click Create-on-Woo).
 *
 * The brand of a new_product_opportunity is NOT stored on the suggestion — it
 * comes from the supplier feed manufacturer. Filtering / grouping Suggestions
 * by brand therefore needs a precompute. This command:
 *
 *   1. Loads the current Woo brand list (TaxonomyResolver::allBrands()).
 *   2. Walks pending new_product_opportunity suggestions, collecting each
 *      suggestion's evidence.sku.
 *   3. Batch-looks-up each SKU's supplier manufacturer(s) from supplier_products
 *      (mirrors DraftFromSuggestionsCommand's mysqli walk).
 *   4. Resolves each SKU to a brand via the shared ResolvesWooBrandKey trait,
 *      classifying it as: already-on-Woo / to-add / not-sourceable
 *      (buildBrandsToAddIndex — the PURE, unit-tested core).
 *   5. Tags each suggestion's evidence with brand + brand_on_woo so Piece 2's
 *      list filters are cheap, and caches a "brands to add" summary
 *      (brand => products-it-would-unlock + sample SKUs) under a stable cache
 *      key so Piece 2's page renders instantly.
 *
 * --dry-run computes + prints the to-add summary but writes NOTHING (no
 * evidence updates, no cache write). Scheduled Mon-Fri 07:50 London, after
 * suppliers:check-stale (07:45). No Claude spend, no storefront writes.
 *
 *   php artisan products:refresh-brands-to-add --dry-run
 *   php artisan products:refresh-brands-to-add
 *   php artisan products:refresh-brands-to-add --limit=500 --dry-run
 */
final class RefreshBrandsToAddCommand extends BaseCommand
{
    use ResolvesWooBrandKey;

    /** Stable cache key Piece 2's "Brands to Add" page reads. */
    public const CACHE_KEY = 'suggestions.brands_to_add';

    /** Cache TTL — 24h; the scheduled Mon-Fri run refreshes well within it. */
    private const CACHE_TTL_SECONDS = 86400;

    /** Max sample SKUs stored per to-add brand (the full count is separate). */
    private const SAMPLE_SKU_CAP = 25;

    protected $signature = 'products:refresh-brands-to-add
        {--dry-run : Compute + print the brands-to-add summary but write nothing (no evidence tags, no cache)}
        {--limit=0 : Max pending suggestions to walk (0 = all)}';

    protected $description = 'Resolve pending-suggestion SKUs → brand, tag evidence.brand/brand_on_woo, cache the brands-to-add summary for the Piece-2 page.';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        // ── 1. Load the current Woo brand list ───────────────────────────
        $wooBrandsByLower = [];
        foreach ($this->taxonomy->allBrands() as $b) {
            $name = trim((string) ($b['name'] ?? ''));
            if ($name !== '') {
                $wooBrandsByLower[mb_strtolower($name)] = $name;
            }
        }
        if ($wooBrandsByLower === []) {
            $this->error('No Woo brand terms found — cannot resolve. Aborting.');

            return SymfonyCommand::FAILURE;
        }
        $this->info('Loaded '.count($wooBrandsByLower).' Woo brand terms.');

        // ── 2. Walk pending suggestions → [suggestionId => sku] ───────────
        /** @var array<int, string> $suggestionSku  suggestion id => sku (case preserved) */
        $suggestionSku = [];
        $query = DB::table('suggestions')
            ->where('kind', 'new_product_opportunity')
            ->where('status', 'pending')
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $query->chunk(200, function ($rows) use (&$suggestionSku): void {
            foreach ($rows as $sug) {
                $ev = json_decode((string) $sug->evidence, true);
                $sku = trim((string) ($ev['sku'] ?? ''));
                if ($sku !== '') {
                    $suggestionSku[(int) $sug->id] = $sku;
                }
            }
        });

        if ($suggestionSku === []) {
            $this->info('No pending new_product_opportunity suggestions to process.');

            return SymfonyCommand::SUCCESS;
        }
        $this->info('Walking '.count($suggestionSku).' pending suggestion(s).');

        // ── 3. Batch supplier_products manufacturer lookup ────────────────
        $skuToManufacturers = $this->fetchManufacturers(array_values(array_unique($suggestionSku)));

        // ── 4. PURE classification + aggregation ──────────────────────────
        $index = $this->buildBrandsToAddIndex($skuToManufacturers, $wooBrandsByLower);

        // ── 5. Tag each suggestion's evidence (skip on --dry-run) ─────────
        if (! $dryRun) {
            $tagged = 0;
            foreach ($suggestionSku as $id => $sku) {
                $perSku = $index['per_sku'][mb_strtolower($sku)] ?? null;
                $suggestion = Suggestion::find($id);
                if ($suggestion === null) {
                    continue;
                }
                $ev = is_array($suggestion->evidence) ? $suggestion->evidence : [];
                $ev['brand'] = $perSku['brand'] ?? null;
                $ev['brand_on_woo'] = $perSku['on_woo'] ?? false;
                $suggestion->update(['evidence' => $ev]);
                $tagged++;
            }
            $this->info("Tagged {$tagged} suggestion(s) with evidence.brand + brand_on_woo.");

            // ── 6. Cache the brands-to-add summary (ksorted list) ─────────
            $toAdd = $index['to_add'];
            ksort($toAdd);
            $brands = [];
            foreach ($toAdd as $brand => $agg) {
                $brands[] = [
                    'brand' => $brand,
                    'count' => $agg['count'],
                    'skus' => $agg['skus'],
                ];
            }
            Cache::put(self::CACHE_KEY, [
                'generated_at' => now()->toIso8601String(),
                'brands' => $brands,
            ], self::CACHE_TTL_SECONDS);
            $this->info('Cached brands-to-add summary under "'.self::CACHE_KEY.'" ('.count($brands).' brand(s), TTL 24h).');
        } else {
            $this->warn('--dry-run — no evidence tags written, no cache updated.');
        }

        // ── 7. Print the to-add summary table (always) ────────────────────
        $this->printToAddTable($index['to_add']);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * PURE brand classification + to-add aggregation. No DB, no mysqli — the
     * remote lookup is done by the caller and passed in as $skuToManufacturers.
     *
     * @param  array<string, array<int,string>>  $skuToManufacturers  lower(sku) => [manufacturers]
     * @param  array<string,string>  $wooBrandsByLower  lower(name) => canonical
     * @return array{
     *   per_sku: array<string, array{brand:?string, on_woo:bool, sourceable:bool}>,
     *   to_add:  array<string, array{count:int, skus:array<int,string>}>
     * }
     */
    public function buildBrandsToAddIndex(array $skuToManufacturers, array $wooBrandsByLower): array
    {
        $perSku = [];
        $toAdd = [];

        foreach ($skuToManufacturers as $skuLower => $mfrs) {
            $skuLower = (string) $skuLower;

            // No supplier manufacturer at all → not-sourceable (a different
            // bucket; NOT a brand to add).
            if ($mfrs === []) {
                $perSku[$skuLower] = ['brand' => null, 'on_woo' => false, 'sourceable' => false];

                continue;
            }

            [$brandKey] = $this->firstResolvableBrandKey($mfrs, $wooBrandsByLower);

            if ($brandKey !== null) {
                // Resolves to an existing Woo brand → already on Woo.
                $perSku[$skuLower] = [
                    'brand' => $wooBrandsByLower[$brandKey],
                    'on_woo' => true,
                    'sourceable' => true,
                ];

                continue;
            }

            // Has manufacturer(s) but none resolve → the operator would add the
            // FIRST manufacturer as a new Woo brand to unlock this SKU.
            $brand = trim((string) $mfrs[0]);
            $perSku[$skuLower] = ['brand' => $brand, 'on_woo' => false, 'sourceable' => true];

            $toAdd[$brand] ??= ['count' => 0, 'skus' => []];
            $toAdd[$brand]['count']++;
            if (count($toAdd[$brand]['skus']) < self::SAMPLE_SKU_CAP) {
                $toAdd[$brand]['skus'][] = $skuLower;
            }
        }

        return ['per_sku' => $perSku, 'to_add' => $toAdd];
    }

    /**
     * Batch-fetch supplier manufacturer(s) per SKU from supplier_products.
     * MIRRORS DraftFromSuggestionsCommand::perform()'s mysqli walk — thin,
     * untested shell (the testable core is buildBrandsToAddIndex).
     *
     * @param  array<int,string>  $skus  case-preserved SKU strings
     * @return array<string, array<int,string>> lower(sku_or_mpn) => [manufacturers]
     */
    private function fetchManufacturers(array $skus): array
    {
        /** @var array<string, array<int,string>> $skuToManufacturers */
        $skuToManufacturers = [];
        // Every walked SKU gets an entry (default []) so buildBrandsToAddIndex
        // classifies a not-in-feed SKU as not-sourceable rather than dropping it.
        foreach ($skus as $sku) {
            $skuToManufacturers[mb_strtolower(trim($sku))] = [];
        }

        $c = $this->resolver->for(IntegrationCredentialKind::SupplierDb);
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new \mysqli(
            (string) $c['host'], (string) $c['username'], (string) $c['password'],
            (string) $c['database'], (int) ($c['port'] ?? 3306),
        );
        if ($m->connect_errno !== 0) {
            $this->error('supplier_db connect failed: '.$m->connect_error);

            // Return the all-empty map — every SKU classifies as not-sourceable.
            return $skuToManufacturers;
        }

        foreach (array_chunk($skus, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $m->prepare("SELECT suppliersku, mpn, manufacturer FROM supplier_products WHERE suppliersku IN ($ph) OR mpn IN ($ph)");
            $params = array_merge($chunk, $chunk);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $mfr = trim((string) $r['manufacturer']);
                if ($mfr === '') {
                    continue;
                }
                // A SKU can match multiple feed rows under one MPN with different
                // manufacturers (product row + warranty/protection-plan row).
                // Collect ALL — append + dedup — so firstResolvableBrandKey can
                // prefer the one that resolves to a Woo brand.
                foreach ([strtolower((string) $r['suppliersku']), strtolower((string) $r['mpn'])] as $k) {
                    if ($k === '' || ! isset($skuToManufacturers[$k])) {
                        continue;
                    }
                    if (! in_array($mfr, $skuToManufacturers[$k], true)) {
                        $skuToManufacturers[$k][] = $mfr;
                    }
                }
            }
            $stmt->close();
        }

        $m->close();

        return $skuToManufacturers;
    }

    /**
     * Print the brands-to-add summary table (brand | count), ksorted.
     *
     * @param  array<string, array{count:int, skus:array<int,string>}>  $toAdd
     */
    private function printToAddTable(array $toAdd): void
    {
        $this->newLine();
        if ($toAdd === []) {
            $this->info('No brands to add — every sourceable pending SKU already resolves to a Woo brand.');

            return;
        }
        ksort($toAdd);
        $rows = [];
        foreach ($toAdd as $brand => $agg) {
            $rows[] = [$brand, $agg['count']];
        }
        $this->info('Brands to add ('.count($toAdd).') — pending products each would unlock:');
        $this->table(['Brand (to add)', 'Pending SKUs'], $rows);
    }
}
