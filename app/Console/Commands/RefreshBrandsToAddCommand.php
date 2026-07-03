<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Concerns\NormalisesBrandNames;
use App\Domain\ProductAutoCreate\Concerns\ResolvesWooBrandKey;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
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
    // 260702-qd8 — normaliseBrandName + isJunkBrand now live in this shared
    // concern (extracted verbatim; used by WooBrandCreator too). Behaviour
    // unchanged — BrandsToAddIndexTest stays green.
    use NormalisesBrandNames;
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
        /** @var array<string, string> $suggestionSku  (string) ULID id => sku (case preserved) */
        $suggestionSku = [];
        $query = DB::table('suggestions')
            ->where('kind', 'new_product_opportunity')
            ->where('status', 'pending')
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $query->chunk(200, function ($rows) use (&$suggestionSku): void {
            $suggestionSku += $this->indexSuggestionSkus($rows);
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

            // 260703-qc0 — pre-warm the Suggestions Brand-filter option list off
            // the web path (the admin page used to run this distinct-JSON scan on
            // every render and 30s-timed-out under load). Forget then rebuild so
            // it reflects the tags we just wrote.
            Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);
            SuggestionResource::brandFilterOptions();
            $this->info('Pre-warmed the Suggestions Brand-filter option list ("'.SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY.'").');
        } else {
            $this->warn('--dry-run — no evidence tags written, no cache updated.');
        }

        // ── 7. Print the to-add summary table (always) ────────────────────
        $this->printToAddTable($index['to_add']);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Map suggestion rows to [ (string) ULID id => sku ], skipping rows with no
     * evidence.sku. Suggestion has a ULID PK — key by string, NEVER (int) (that
     * collapses every '01…' id to 1). Pure; unit-tested.
     *
     * @param  iterable<int,object>  $rows  rows with ->id and ->evidence (JSON string or array)
     * @return array<string,string>
     */
    public function indexSuggestionSkus(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $sug) {
            $ev = is_array($sug->evidence ?? null) ? $sug->evidence : json_decode((string) ($sug->evidence ?? ''), true);
            $sku = trim((string) ($ev['sku'] ?? ''));
            if ($sku !== '') {
                $out[(string) $sug->id] = $sku;
            }
        }

        return $out;
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

        // Case-insensitive accumulation for the to-add bucket. Keyed by
        // lower(brand); each group tracks per-variant counts (for canonical
        // pick) + the sample SKUs it would unlock.
        /** @var array<string, array{counts: array<string,int>, skus: array<int,string>}> $groups */
        $groups = [];

        foreach ($skuToManufacturers as $skuLower => $mfrs) {
            $skuLower = (string) $skuLower;

            // Normalise the manufacturer list up-front — HTML-decode + trim +
            // collapse inner whitespace, dropping blanks — so BOTH the Woo-brand
            // resolution AND the to-add name see clean, decoded names (e.g.
            // 'VOGEL&#039;S' can now match a Woo 'Vogel\'s').
            $mfrs = array_values(array_filter(
                array_map(fn ($m): string => $this->normaliseBrandName((string) $m), $mfrs),
                static fn (string $m): bool => $m !== '',
            ));

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
            $brand = $mfrs[0];

            // Junk (consumables / non-brand buckets) → sourceable but never
            // offered as a creatable brand. per_sku brand=null; not counted.
            if ($this->isJunkBrand($brand)) {
                $perSku[$skuLower] = ['brand' => null, 'on_woo' => false, 'sourceable' => true];

                continue;
            }

            // Accumulate into a case-insensitive group; brand holds a placeholder
            // group key (remapped to the canonical after the loop).
            $groupKey = mb_strtolower($brand);
            $groups[$groupKey]['counts'][$brand] = ($groups[$groupKey]['counts'][$brand] ?? 0) + 1;
            $groups[$groupKey]['skus'][] = $skuLower;
            $perSku[$skuLower] = ['brand' => $groupKey, 'on_woo' => false, 'sourceable' => true];
        }

        // Collapse each case-insensitive group into ONE canonical brand
        // (mixed-case preferred, acronyms preserved) with summed count + merged
        // sample SKUs, then remap the per_sku placeholders to that canonical.
        $keyToCanonical = [];
        foreach ($groups as $groupKey => $group) {
            $canonical = $this->pickCanonicalBrand($group['counts']);
            $keyToCanonical[$groupKey] = $canonical;
            $toAdd[$canonical] = [
                'count' => array_sum($group['counts']),
                'skus' => array_slice(array_values(array_unique($group['skus'])), 0, self::SAMPLE_SKU_CAP),
            ];
        }
        foreach ($perSku as $sku => $data) {
            if ($data['brand'] !== null && isset($keyToCanonical[$data['brand']])) {
                $perSku[$sku]['brand'] = $keyToCanonical[$data['brand']];
            }
        }

        return ['per_sku' => $perSku, 'to_add' => $toAdd];
    }

    /**
     * Canonical display for a group of case-variant brand names: prefer a variant
     * containing a lowercase letter (proper-case, e.g. 'Brother' over 'BROTHER');
     * among the pool pick the highest total count, tie-break alphabetical. Never
     * title-cases (keeps acronyms 'APC'/'HP'/'2N' intact).
     *
     * @param  array<string,int>  $counts  variant name => count
     */
    private function pickCanonicalBrand(array $counts): string
    {
        $variants = array_keys($counts);
        $mixed = array_values(array_filter($variants, static fn (string $v): bool => (bool) preg_match('/\p{Ll}/u', $v)));
        $pool = $mixed !== [] ? $mixed : $variants;
        usort($pool, static fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

        return $pool[0];
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
