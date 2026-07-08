<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\NormalisesEan;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\EanSearchClient;
use App\Domain\ProductAutoCreate\Services\IcecatClient;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooGtinPublisher;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260607-cgd — products:backfill-merchant-feed.
 * Extended 260607-g25 — Icecat EAN fallback for stuck SKUs.
 * Extended 260607-hxa — EAN-search.org replaces Icecat as the default EAN-fallback
 *                       provider (config-switchable via integrations.ean_fallback_provider;
 *                       Icecat retained for image lookup only).
 *
 * Lifts the 89% Google Merchant Center disapproval rate on live products
 * (3,493 TRIPLE FAIL rows — no EAN + no brand_id + no category_id) by pulling
 * EAN + manufacturer from supplier_db.feeds_products and chaining Claude
 * category assignment via products:assign-taxonomy.
 *
 * Three field paths, all idempotent (WHERE clauses exclude already-populated rows):
 *   --field=ean       supplier_db.ean → NormalisesEan trait → products.ean
 *                     + (260607-hxa) EAN-search.org / Icecat fallback by brand+MPN
 *                       when supplier_db returns invalid/missing EAN. Provider
 *                       resolved via config('integrations.ean_fallback_provider')
 *                       (default 'ean_search', 'icecat' for A/B). Default ON;
 *                       --no-icecat-fallback restores 260607-cgd byte-identical
 *                       behaviour. Per-run cap via --max-icecat-spend-pence (default
 *                       200p = £2; ~0.03p/query for ean_search, ~0.2p/query for icecat).
 *                       Cap is in-memory, per-process — a killed run loses the counter.
 *   --field=brand     supplier_db.manufacturer → TaxonomyResolver fuzzy ≥ 0.85 → products.brand_id
 *   --field=category  Claude via products:assign-taxonomy in 50-SKU batches (~1p/SKU)
 *
 * Default is --dry-run (4-quadrant counts + 20-row sample + ZERO writes). Live
 * runs OMIT --dry-run. Optional --resync chains products:resync-to-woo on the
 * SUCCESSFULLY UPDATED SKUs only (never the candidate set, never legacy SKUs).
 *
 *   php artisan products:backfill-merchant-feed --field=ean --dry-run
 *   php artisan products:backfill-merchant-feed --field=ean,brand --resync
 *   php artisan products:backfill-merchant-feed --field=category --resync
 *   php artisan products:backfill-merchant-feed --field=ean --no-icecat-fallback   # 260607-cgd parity
 *   php artisan products:backfill-merchant-feed --field=ean --max-icecat-spend-pence=50
 *   EAN_FALLBACK_PROVIDER=icecat php artisan products:backfill-merchant-feed --field=ean
 */
// Not `final` so the Pest feature test can override the protected
// lookupSupplierEans / lookupSupplierManufacturers methods via an anonymous
// subclass (OPTION A from PLAN.md — mirrors the 260607-9c6 WooDbSnapshotter
// runDumpCommand pattern and the existing TaxonomyResolver test surface).
class BackfillMerchantFeedCommand extends BaseCommand
{
    // Single source of truth for EAN validation (shared with GenerateProductDraftsCommand).
    use NormalisesEan;

    protected $signature = 'products:backfill-merchant-feed
        {--field=ean : Comma-separated fields to backfill: ean, brand, category}
        {--skus= : Comma-separated SKU list; default = all live products missing the requested field(s)}
        {--limit=0 : Max products this run; 0 = unbounded}
        {--dry-run : Print counts + 20-row sample; do NOT write}
        {--resync : After backfill, run products:resync-to-woo on the SUCCESSFULLY UPDATED SKUs only (re-feeds Merchant Center)}
        {--push-to-woo : After backfill, publish each updated SKU\'s EAN to its live Woo GTIN (global_unique_id) via WooGtinPublisher. Off by default.}
        {--no-confirm : Skip interactive y/N confirmations (use for cron / non-interactive runs)}
        {--icecat-fallback : DEPRECATED — alias of --ean-fallback for backwards compatibility. DEFAULT ON.}
        {--ean-fallback : Alias of --icecat-fallback. DEFAULT ON.}
        {--no-icecat-fallback : Opt out of EAN-lookup fallback (restores 260607-cgd behaviour exactly).}
        {--max-icecat-spend-pence=200 : Cap cumulative EAN-lookup spend per run (~0.03p/query for ean_search, ~0.2p/query for icecat; default 200p = £2).}';

    protected $description = 'Backfill EAN/brand/category from supplier_db onto live products to lift Google Merchant Center disapproval rate.';

    /**
     * Captured at perform() start so the auth context is preserved across
     * nested Artisan::call() chains. Null for cron / queue runs.
     */
    private ?User $triggeringUser = null;

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
        private readonly IcecatClient $icecat,
        private readonly EanSearchClient $eanSearch,
        private readonly AdCandidateScanner $adCandidates,
        private readonly WooGtinPublisher $gtinPublisher,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $this->triggeringUser = auth()->user();

        $fields = array_values(array_filter(
            array_map('trim', explode(',', strtolower((string) $this->option('field')))),
            static fn (string $s): bool => $s !== '',
        ));
        $allowed = ['ean', 'brand', 'category'];
        foreach ($fields as $field) {
            if (! in_array($field, $allowed, true)) {
                $this->error("Unknown --field token '{$field}'. Allowed: ean, brand, category.");

                return SymfonyCommand::FAILURE;
            }
        }
        if ($fields === []) {
            $this->error('--field is required (comma-separated: ean, brand, category).');

            return SymfonyCommand::FAILURE;
        }

        $skusFilter = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('skus'))),
            static fn (string $s): bool => $s !== '',
        ));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $resync = (bool) $this->option('resync');
        $pushToWoo = (bool) $this->option('push-to-woo');
        $noConfirm = (bool) $this->option('no-confirm') || ! $this->input->isInteractive();

        // Icecat fallback resolution. The negative-only flag is the idiomatic
        // Symfony/Artisan workaround for "default true" semantics — both
        // --icecat-fallback (no-op when default ON) and --no-icecat-fallback
        // are accepted in the signature; the negative one is what we actually
        // read because Symfony's Option API does not cleanly express
        // default-true negatable booleans.
        $icecatFallback = ! (bool) $this->option('no-icecat-fallback');
        $maxIcecatSpendPence = max(0, (int) $this->option('max-icecat-spend-pence'));

        $this->info('Fields requested: '.implode(', ', $fields).($dryRun ? '  (dry-run)' : '  (LIVE)'));

        // 260607-pys Task 2: when --skus is OMITTED, narrow candidate
        // selection to AdCandidateScanner's golden-target SKU set so the
        // backfill only spends Claude/network credit on products that
        // actually qualify for the Google Ads campaign. When --skus IS
        // passed the scanner is bypassed entirely (operator override).
        //
        // Threshold rationale: page default is £199 for the campaign-
        // planning use case (operator browsing /admin/ad-candidates);
        // backfill default stays at the historical £300 (30000p) — a
        // tighter cut for the "spend Claude credit only on the BEST
        // golden-ad targets" automated batch path. Both call the same
        // scanner with different thresholds. Single SQL surface.
        $goldenSkus = null;
        if ($skusFilter === []) {
            $goldenSkus = $this->adCandidates
                ->scan(minMarginPence: 30000, stockRequired: true, beatRequired: true)
                ->pluck('sku')
                ->filter(static fn ($s): bool => is_string($s) && $s !== '')
                ->values()
                ->all();
            $goldenCount = count($goldenSkus);
            $this->info("Golden-target narrowing ENABLED: {$goldenCount} SKU(s) qualify (£300 margin + stock + beat).");
        }

        $updatedSkus = [];

        if (in_array('ean', $fields, true)) {
            $this->newLine();
            $this->info('=== EAN backfill ===');

            // Resolve active EAN-lookup provider via config (env-overridable).
            // Misconfigured value falls back to 'ean_search' (safe default).
            $provider = (string) config('integrations.ean_fallback_provider', 'ean_search');
            if (! in_array($provider, ['ean_search', 'icecat'], true)) {
                $provider = 'ean_search';
            }

            // Pre-flight banner — operator consent / behaviour signal.
            if ($icecatFallback) {
                $capGbp = number_format($maxIcecatSpendPence / 100, 2);
                $perQueryGbp = $provider === 'icecat' ? '0.2p' : '0.03p';
                $this->info("EAN lookup fallback ENABLED via {$provider} (~{$perQueryGbp}/query, budget cap £{$capGbp}).");
            } else {
                $this->info('EAN lookup fallback DISABLED — supplier_db only (260607-cgd parity).');
            }
            $this->backfillEan($dryRun, $skusFilter, $limit, $updatedSkus, $icecatFallback, $maxIcecatSpendPence, $provider, $goldenSkus);
        }

        if (in_array('brand', $fields, true)) {
            $this->newLine();
            $this->info('=== Brand backfill ===');
            $this->backfillBrand($dryRun, $skusFilter, $limit, $updatedSkus, $goldenSkus);
        }

        if (in_array('category', $fields, true)) {
            $this->newLine();
            $this->info('=== Category backfill ===');
            $aborted = $this->backfillCategory($dryRun, $skusFilter, $limit, $noConfirm, $updatedSkus, $goldenSkus);
            if ($aborted) {
                return SymfonyCommand::FAILURE;
            }
        }

        // --push-to-woo: publish ONLY the GTIN (global_unique_id) of each
        // SUCCESSFULLY-updated SKU to its live Woo product via WooGtinPublisher.
        // Brand/category still go via their existing paths (resync / product_brand).
        // Flag-absent behaviour is byte-identical — this whole block is skipped.
        if ($pushToWoo && $updatedSkus !== [] && ! $dryRun) {
            $uniq = array_values(array_unique($updatedSkus));
            $published = 0;
            $collision = 0;
            $skipped = 0;
            foreach (Product::query()->whereIn('sku', $uniq)->get() as $p) {
                switch ($this->gtinPublisher->publish($p, $p->ean)) {
                    case 'published': $published++;
                        break;
                    case 'collision': $collision++;
                        break;
                    default: $skipped++;
                        break;
                }
            }
            $this->info("Pushed GTIN to Woo — {$published} published, {$collision} collision(s), {$skipped} skipped.");
        }

        // --resync: only on SUCCESSFULLY UPDATED SKUs (never the candidate set).
        if ($resync && $updatedSkus !== []) {
            $uniq = array_values(array_unique($updatedSkus));
            $count = count($uniq);
            $this->newLine();
            $this->info("Resync candidates: {$count} SKU(s) successfully updated this run.");

            $proceed = true;
            if (! $noConfirm) {
                $proceed = $this->confirm("Push {$count} updated SKU(s) to Woo via products:resync-to-woo?", true);
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

    /**
     * EAN field path. Builds the candidate set (status=publish + missing EAN),
     * looks up the supplier EAN per SKU, classifies into 4 quadrants (when
     * EAN-lookup fallback is OFF) or 7 buckets (when ON), prints counts + sample,
     * and on live writes only validated rows.
     *
     * Cost-counter semantics (260607-g25, refined 260607-hxa): the fallback
     * spend counter is cumulative per-process, in-memory, not persisted. A
     * killed run loses the counter; the next run starts at 0. Operator's
     * safety net is the per-run cap (--max-icecat-spend-pence). Hundredths of
     * a penny are the internal unit so 0.03p/query (ean_search) is integer-
     * representable: ean_search ticks 3 hundredths/query, icecat ticks 20
     * hundredths/query (= 0.2p).
     *
     * Recovered-from-EAN-lookup rows ride the SAME $would write path as
     * supplier rows — the only difference is provenance bookkeeping for the
     * outcome table + the per-row "Source" column.
     *
     * @param  array<int, string>  $skusFilter
     * @param  array<int, string>  &$updatedSkus  populated with successfully-updated SKUs
     * @param  string  $provider  'ean_search' (default) or 'icecat' (A/B comparison via env)
     * @param  array<int, string>|null  $goldenSkus  golden-target narrowing list (260607-pys);
     *                                               null when --skus override is in effect
     */
    private function backfillEan(
        bool $dryRun,
        array $skusFilter,
        int $limit,
        array &$updatedSkus,
        bool $icecatFallback = false,
        int $maxIcecatSpendPence = 0,
        string $provider = 'ean_search',
        ?array $goldenSkus = null,
    ): void {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('ean')->orWhere('ean', '');
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
        } elseif ($goldenSkus !== null) {
            // Empty golden set legitimately means "no candidates" — pass
            // an unmatchable list rather than skip the constraint, so an
            // operator who runs the command on a day when zero products
            // qualify doesn't accidentally process the entire catalogue.
            $query->whereIn('sku', $goldenSkus === [] ? ['__NONE__'] : $goldenSkus);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var array<string, string> $candidates  sku => sku (we only need the SKU list) */
        $candidates = $query
            ->orderBy('id')
            ->pluck('sku')
            ->filter(static fn ($s): bool => is_string($s) && $s !== '')
            ->mapWithKeys(static fn (string $s) => [$s => $s])
            ->all();

        $count = count($candidates);
        if ($count === 0) {
            $this->info('EAN backfill: 0 candidate products.');

            return;
        }

        $this->info("EAN backfill: {$count} candidate products.");

        $candidateSkus = array_values($candidates);
        $candidateSkusLower = array_map(
            static fn (string $s): string => strtolower(trim($s)),
            $candidateSkus,
        );

        $supplierEanBySku = $this->lookupSupplierEans($candidateSkusLower);

        // Pre-load Product rows + brand-name map ONCE when fallback is ON
        // (skip the Woo brand-cache cost when fallback is OFF — matches the
        // efficiency of the supplier_db batch lookup).
        /** @var array<string, Product> $productBySku */
        $productBySku = [];
        $brandNameById = [];
        if ($icecatFallback) {
            $productBySku = Product::query()
                ->whereIn('sku', $candidateSkus)
                ->get()
                ->keyBy('sku')
                ->all();

            foreach ($this->taxonomy->allBrands() as $term) {
                $id = (int) ($term['id'] ?? 0);
                $name = (string) ($term['name'] ?? '');
                if ($id > 0 && $name !== '') {
                    $brandNameById[$id] = $name;
                }
            }
        }

        // Classify each candidate into one of (4 or 7) outcomes.
        $would = [];
        $sourceBySku = [];                // sku => 'supplier' | 'ean_lookup'
        $skippedInvalid = [];              // when fallback OFF — kept for output parity
        $skippedNoMatch = [];
        $alreadyPopulated = [];            // empty by construction — WHERE clause excludes
        $recoveredFromEanLookup = [];      // sku => validated EAN
        $eanLookupNoMatch = [];
        $eanLookupInvalidEan = [];
        $eanLookupBudgetExhausted = [];
        // Hundredths-of-pence integer arithmetic so 0.03p/query (ean_search) is
        // integer-representable. ean_search = 3 hundredths/query (= 0.03p);
        // icecat = 20 hundredths/query (= 0.2p).
        $fallbackSpendHundredthPence = 0;
        $maxFallbackSpendHundredthPence = $maxIcecatSpendPence * 100;
        $perQueryCost = $provider === 'icecat' ? 20 : 3;
        $sample = [];

        foreach ($candidateSkus as $sku) {
            $key = strtolower(trim($sku));
            if (! array_key_exists($key, $supplierEanBySku)) {
                $skippedNoMatch[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, '(no supplier row)', '-', 'skipped_no_supplier_match'];
                }

                continue;
            }
            $rawEan = $supplierEanBySku[$key];
            $validated = $this->normaliseEan($rawEan);
            if ($validated !== null) {
                $would[$sku] = $validated;
                $sourceBySku[$sku] = 'supplier';
                if (count($sample) < 20) {
                    $sample[] = [
                        $sku,
                        $validated,
                        'supplier',
                        $dryRun ? 'would_update_from_supplier' : 'updated_from_supplier',
                    ];
                }

                continue;
            }

            // Supplier returned invalid EAN. Branch on fallback flag.
            if (! $icecatFallback) {
                $skippedInvalid[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, (string) $rawEan, '-', 'skipped_invalid_ean'];
                }

                continue;
            }

            // EAN-lookup fallback path (provider = ean_search by default; icecat
            // for forensic A/B). Cost gate: reject when the NEXT call would push
            // us past the cap. maxIcecatSpendPence=0 → "no cap"
            // (--max-icecat-spend-pence=0 explicitly).
            if ($maxFallbackSpendHundredthPence > 0
                && ($fallbackSpendHundredthPence + $perQueryCost) > $maxFallbackSpendHundredthPence) {
                $eanLookupBudgetExhausted[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, (string) $rawEan, $provider, 'ean_lookup_budget_exhausted'];
                }

                continue;
            }

            $product = $productBySku[$sku] ?? null;
            $brand = null;
            $mpn = null;
            if ($product !== null) {
                $brandId = (int) ($product->brand_id ?? 0);
                if ($brandId > 0 && isset($brandNameById[$brandId])) {
                    $brand = $brandNameById[$brandId];
                }
                $mpn = (string) $product->sku;
            } else {
                // Product row vanished between candidate-pluck and detail load
                // (rare — concurrent delete). Treat as no-match to be safe.
                $eanLookupNoMatch[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, '(product row gone)', $provider, 'ean_lookup_no_match'];
                }

                continue;
            }

            $fallbackSpendHundredthPence += $perQueryCost;
            $candidate = $provider === 'icecat'
                ? $this->icecat->lookupGtinByMpn($brand, $mpn)
                : $this->eanSearch->lookupGtinByMpn($brand, $mpn);

            if ($candidate === null) {
                $eanLookupNoMatch[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, "(no {$provider} match)", $provider, 'ean_lookup_no_match'];
                }

                continue;
            }

            $validatedFallback = $this->normaliseEan($candidate);
            if ($validatedFallback === null) {
                $eanLookupInvalidEan[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, mb_substr((string) $candidate, 0, 32), $provider, 'ean_lookup_invalid_ean'];
                }

                continue;
            }

            $would[$sku] = $validatedFallback;
            $sourceBySku[$sku] = 'ean_lookup';
            $recoveredFromEanLookup[] = $sku;
            if (count($sample) < 20) {
                $sample[] = [
                    $sku,
                    $validatedFallback,
                    $provider,
                    $dryRun ? 'would_update_from_ean_lookup' : 'updated_from_ean_lookup',
                ];
            }
        }

        $this->newLine();
        if ($icecatFallback) {
            // 7-row outcome table — fallback active. supplier-only count is
            // the supplier-source slice of $would; recovered_from_ean_lookup
            // is its own row. $skippedInvalid is empty by construction here
            // (every previously-invalid row went into one of the ean_lookup_*
            // buckets or into recoveredFromEanLookup) but the row is kept at
            // count 0 so the column ordering stays parallel to the
            // fallback-off output.
            $supplierWouldCount = count($would) - count($recoveredFromEanLookup);
            $this->table(
                ['Outcome', 'Count'],
                [
                    ['would_update_from_supplier', $supplierWouldCount],
                    ['recovered_from_ean_lookup', count($recoveredFromEanLookup)],
                    ['skipped_invalid_ean', count($skippedInvalid)],
                    ['ean_lookup_no_match', count($eanLookupNoMatch)],
                    ['ean_lookup_invalid_ean', count($eanLookupInvalidEan)],
                    ['ean_lookup_budget_exhausted', count($eanLookupBudgetExhausted)],
                    ['skipped_no_supplier_match', count($skippedNoMatch)],
                    ['already_populated_excluded', count($alreadyPopulated)],
                ],
            );
        } else {
            // 4-row outcome table — 260607-cgd byte-identical parity.
            $this->table(
                ['Outcome', 'Count'],
                [
                    ['would_update', count($would)],
                    ['skipped_invalid_ean', count($skippedInvalid)],
                    ['skipped_no_supplier_match', count($skippedNoMatch)],
                    ['already_populated_excluded', count($alreadyPopulated)],
                ],
            );
        }

        $this->newLine();
        $this->info('Sample (first 20):');
        if ($icecatFallback) {
            $this->table(['SKU', 'Candidate EAN', 'Source', 'Outcome'], $sample);
        } else {
            // Strip the Source column for parity output.
            $sampleNoSource = array_map(
                static fn (array $row): array => [$row[0], $row[1], $row[3] ?? $row[2]],
                $sample,
            );
            // For fallback-off, the original (3-column) sample shape used
            // outcome tokens 'would_update' / 'skipped_invalid_ean' / etc.
            // Replace 'would_update_from_supplier' with 'would_update' so
            // the parity output matches 260607-cgd exactly.
            $sampleNoSource = array_map(static function (array $row): array {
                $outcome = (string) $row[2];
                $outcome = str_replace('would_update_from_supplier', 'would_update', $outcome);
                $outcome = str_replace('updated_from_supplier', 'updated', $outcome);
                $row[2] = $outcome;

                return $row;
            }, $sampleNoSource);
            $this->table(['SKU', 'Candidate EAN', 'Outcome'], $sampleNoSource);
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run — exiting EAN pass without writes.');
            if ($icecatFallback) {
                $queries = $perQueryCost > 0 ? (int) ($fallbackSpendHundredthPence / $perQueryCost) : 0;
                $pence = number_format($fallbackSpendHundredthPence / 100, 2);
                $this->info("EAN lookup spend this run: {$pence}p ({$queries} queries via {$provider}).");
            }

            return;
        }

        // Live path: chunk into 500-row batches.
        $batches = array_chunk($would, 500, true);
        $supplierUpdated = 0;
        $eanLookupUpdated = 0;
        foreach ($batches as $batch) {
            foreach ($batch as $sku => $ean) {
                $affected = DB::table('products')
                    ->where('sku', $sku)
                    ->update(['ean' => $ean, 'updated_at' => now()]);
                if ($affected > 0) {
                    $updatedSkus[] = $sku;
                    if (($sourceBySku[$sku] ?? 'supplier') === 'ean_lookup') {
                        $eanLookupUpdated++;
                    } else {
                        $supplierUpdated++;
                    }
                }
            }
        }
        $total = $supplierUpdated + $eanLookupUpdated;
        // Operator UX (260607-g25 Task 4 / 260607-hxa rename): consistent summary
        // shape so log scrapers see the same fields regardless of fallback flag.
        // When fallback is OFF, eanLookupCount is always 0 and the spend line is
        // suppressed.
        $this->info("Updated {$total} product(s) with EAN: {$supplierUpdated} from supplier_db, {$eanLookupUpdated} recovered from EAN-lookup ({$provider}).");
        if ($icecatFallback) {
            $queries = $perQueryCost > 0 ? (int) ($fallbackSpendHundredthPence / $perQueryCost) : 0;
            $pence = number_format($fallbackSpendHundredthPence / 100, 2);
            $this->info("EAN lookup spend this run: {$pence}p ({$queries} queries via {$provider}).");
        }
    }

    /**
     * Brand field path. Builds the candidate set (status=publish + brand_id
     * null or 0), looks up supplier manufacturer per SKU, resolves each via
     * TaxonomyResolver fuzzy ≥ 0.85 against the Woo brand taxonomy, classifies
     * into 3 buckets (no_supplier_manufacturer / fuzzy_below_threshold /
     * would_update | updated), prints counts + 20-row sample, and on live
     * writes brand_id only for resolved matches.
     *
     * The fuzzy threshold is owned by TaxonomyResolver (single source of
     * truth per 73ac682) — never duplicated here.
     *
     * @param  array<int, string>  $skusFilter
     * @param  array<int, string>  &$updatedSkus  populated with successfully-updated SKUs
     * @param  array<int, string>|null  $goldenSkus  golden-target narrowing list (260607-pys)
     */
    private function backfillBrand(
        bool $dryRun,
        array $skusFilter,
        int $limit,
        array &$updatedSkus,
        ?array $goldenSkus = null,
    ): void {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('brand_id')->orWhere('brand_id', 0);
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
        } elseif ($goldenSkus !== null) {
            $query->whereIn('sku', $goldenSkus === [] ? ['__NONE__'] : $goldenSkus);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $candidateSkus = $query
            ->orderBy('id')
            ->pluck('sku')
            ->filter(static fn ($s): bool => is_string($s) && $s !== '')
            ->values()
            ->all();

        $count = count($candidateSkus);
        if ($count === 0) {
            $this->info('Brand backfill: 0 candidate products.');

            return;
        }

        $this->info("Brand backfill: {$count} candidate products.");

        $candidateSkusLower = array_map(
            static fn (string $s): string => strtolower(trim($s)),
            $candidateSkus,
        );

        $supplierMfrBySku = $this->lookupSupplierManufacturers($candidateSkusLower);

        // Pre-index brand names by id for sample display.
        $brandNameById = [];
        foreach ($this->taxonomy->allBrands() as $term) {
            $id = (int) ($term['id'] ?? 0);
            $name = (string) ($term['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $brandNameById[$id] = $name;
            }
        }

        $would = [];                  // sku => brand_id (resolved)
        $skippedNoMfr = [];
        $skippedBelowThreshold = [];
        $sample = [];

        foreach ($candidateSkus as $sku) {
            $key = strtolower(trim($sku));
            $mfr = trim((string) ($supplierMfrBySku[$key] ?? ''));
            if ($mfr === '') {
                $skippedNoMfr[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, '(no manufacturer)', '', '', 'skipped_no_supplier_manufacturer'];
                }

                continue;
            }
            $brandId = $this->taxonomy->resolveBrand($mfr);
            if ($brandId === null) {
                $skippedBelowThreshold[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, $mfr, '', '', 'skipped_fuzzy_below_threshold'];
                }

                continue;
            }
            $would[$sku] = $brandId;
            if (count($sample) < 20) {
                $sample[] = [
                    $sku,
                    $mfr,
                    $brandNameById[$brandId] ?? '(unknown)',
                    (string) $brandId,
                    $dryRun ? 'would_update' : 'updated',
                ];
            }
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['would_update', count($would)],
                ['skipped_fuzzy_below_threshold', count($skippedBelowThreshold)],
                ['skipped_no_supplier_manufacturer', count($skippedNoMfr)],
                ['already_populated_excluded', 0], // empty by construction
            ],
        );

        $this->newLine();
        $this->info('Sample (first 20):');
        $this->table(
            ['SKU', 'Manufacturer', 'Resolved brand', 'brand_id', 'Outcome'],
            $sample,
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run — exiting brand pass without writes.');

            return;
        }

        // Live path: chunk into 500-row batches.
        $batches = array_chunk($would, 500, true);
        $updated = 0;
        foreach ($batches as $batch) {
            foreach ($batch as $sku => $brandId) {
                $affected = DB::table('products')
                    ->where('sku', $sku)
                    ->update(['brand_id' => $brandId, 'updated_at' => now()]);
                if ($affected > 0) {
                    $updatedSkus[] = $sku;
                    $updated++;
                }
            }
        }
        $this->info("Updated {$updated} product(s) with brand_id.");
    }

    /**
     * Category field path. Chains products:assign-taxonomy (Claude per SKU)
     * in 50-SKU batches. Returns TRUE if the operator aborted at the cost
     * confirmation gate, FALSE otherwise (success / dry-run / no-candidates).
     *
     * Cost banner shows BEFORE any spend (~1p/SKU). Interactive runs gate on
     * y/N confirmation unless --no-confirm is passed. Non-interactive runs
     * (cron / queue) without --no-confirm ABORT — operator must explicitly
     * opt in to spending Claude credit without a TTY.
     *
     * Successfully-updated SKU set is the intersection of (was missing
     * before this batch) ∩ (present after this batch) — attributes only the
     * delta this run produced, not legacy assignments.
     *
     * @param  array<int, string>  $skusFilter
     * @param  array<int, string>  &$updatedSkus  populated with successfully-updated SKUs
     * @param  array<int, string>|null  $goldenSkus  golden-target narrowing list (260607-pys)
     */
    private function backfillCategory(
        bool $dryRun,
        array $skusFilter,
        int $limit,
        bool $noConfirm,
        array &$updatedSkus,
        ?array $goldenSkus = null,
    ): bool {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('category_id')->orWhere('category_id', 0);
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
        } elseif ($goldenSkus !== null) {
            $query->whereIn('sku', $goldenSkus === [] ? ['__NONE__'] : $goldenSkus);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $candidateSkus = $query
            ->orderBy('id')
            ->pluck('sku')
            ->filter(static fn ($s): bool => is_string($s) && $s !== '')
            ->values()
            ->all();

        $count = count($candidateSkus);
        if ($count === 0) {
            $this->info('Category backfill: 0 candidate products.');

            return false;
        }

        $costPence = $count * 1; // ~1p/SKU per task background; conservative.
        $costGbp = number_format($costPence / 100, 2);
        $this->info(
            "Category backfill candidates: {$count}. "
            ."Estimated Claude spend: ~{$costPence}p (~£{$costGbp}). Field: category."
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Sample (first 20 SKUs):');
            foreach (array_slice($candidateSkus, 0, 20) as $sku) {
                $this->line("  - {$sku}");
            }
            $this->newLine();
            $this->info('Dry-run — exiting category pass without spending Claude credit.');

            return false;
        }

        // Live path interactive guard.
        $isTty = function_exists('posix_isatty') && @posix_isatty(STDIN);
        if (! $noConfirm) {
            if ($isTty) {
                if (! $this->confirm(
                    "About to spend ~£{$costGbp} on Claude for category backfill. Proceed?",
                    false,
                )) {
                    $this->warn('Aborted by operator.');

                    return true;
                }
            } else {
                $this->error('Non-interactive run without --no-confirm. Refusing to spend Claude credit without explicit opt-in.');

                return true;
            }
        }

        // Chunk into 50-SKU batches, chain products:assign-taxonomy per batch.
        $batches = array_chunk($candidateSkus, 50);
        $updated = 0;
        foreach ($batches as $i => $batch) {
            $batchNo = $i + 1;
            $this->newLine();
            $this->info("==> products:assign-taxonomy (batch {$batchNo}/".count($batches).', '.count($batch).' SKUs)');

            // Baseline: which SKUs in this batch were missing category_id BEFORE the call?
            $beforeMissing = Product::whereIn('sku', $batch)
                ->where(function ($q): void {
                    $q->whereNull('category_id')->orWhere('category_id', 0);
                })
                ->pluck('sku')
                ->all();

            $exit = Artisan::call('products:assign-taxonomy', ['--skus' => implode(',', $batch)]);
            $this->line(Artisan::output());

            // Successfully updated = (missing before) ∩ (has category after)
            $afterAssigned = Product::whereIn('sku', $batch)
                ->whereNotNull('category_id')
                ->where('category_id', '!=', 0)
                ->pluck('sku')
                ->all();

            $batchUpdated = array_values(array_intersect($beforeMissing, $afterAssigned));
            foreach ($batchUpdated as $sku) {
                $updatedSkus[] = $sku;
            }
            $updated += count($batchUpdated);

            if ($exit !== 0) {
                $this->warn("Batch {$batchNo} exited {$exit} — continuing with remaining batches.");
            }
        }

        $this->newLine();
        $this->info("Category backfill: {$updated} products updated.");

        return false;
    }

    /**
     * Lookup supplier EAN by SKU key (LOWER(TRIM(suppliersku)) preferred,
     * LOWER(TRIM(mpn)) fallback). Returns map of sku_key => raw EAN string.
     *
     * `protected` so the Pest test can override via an anonymous subclass and
     * skip the real mysqli boundary (matches the 260607-9c6 H-2 runDumpCommand
     * test pattern).
     *
     * stock-separate-not-applicable: both SQL sites inside this method select
     * suppliersku/mpn + ean only — they do not read .stock. The 260609-rie
     * dual-file fix only matters for reads of feeds_products.stock. Buy-price
     * decisions in this command are fixed transitively via products.buy_price
     * (already written by the now-corrected SupplierDbSyncCommand). See PLAN
     * scope decision in .planning/quick/260609-rie-... directory.
     *
     * @param  array<int, string>  $candidateSkus  lowercase, trimmed
     * @return array<string, string>
     */
    protected function lookupSupplierEans(array $candidateSkus): array
    {
        if ($candidateSkus === []) {
            return [];
        }

        $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);

        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(
            (string) $creds['host'],
            (string) $creds['username'],
            (string) $creds['password'],
            (string) $creds['database'],
            (int) ($creds['port'] ?? 3306),
        );
        if ($db->connect_errno !== 0) {
            throw new \RuntimeException("Supplier DB connect failed (errno={$db->connect_errno}): {$db->connect_error}");
        }

        // Build escaped IN-list of candidate SKUs.
        $escaped = array_map(
            static fn (string $s): string => "'".$db->real_escape_string($s)."'",
            $candidateSkus,
        );
        $inList = implode(',', $escaped);

        $out = [];

        // 1) suppliersku pass (preferred — supplier catalogue key).
        $result = $db->query(
            'SELECT LOWER(TRIM(suppliersku)) AS sku_key, ean '
            ."FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(suppliersku)) IN ({$inList})",
            MYSQLI_USE_RESULT,
        );
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $key = (string) $row['sku_key'];
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                $out[$key] = (string) ($row['ean'] ?? '');
            }
            $result->free();
        }

        // 2) mpn pass — only for SKUs still unresolved.
        $remaining = array_values(array_diff($candidateSkus, array_keys($out)));
        if ($remaining !== []) {
            $escapedR = array_map(
                static fn (string $s): string => "'".$db->real_escape_string($s)."'",
                $remaining,
            );
            $inListR = implode(',', $escapedR);
            $result = $db->query(
                'SELECT LOWER(TRIM(mpn)) AS sku_key, ean '
                ."FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(mpn)) IN ({$inListR})",
                MYSQLI_USE_RESULT,
            );
            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $key = (string) $row['sku_key'];
                    if ($key === '' || isset($out[$key])) {
                        continue;
                    }
                    $out[$key] = (string) ($row['ean'] ?? '');
                }
                $result->free();
            }
        }

        $db->close();

        return $out;
    }

    /**
     * Lookup supplier manufacturer string by SKU key (suppliersku preferred,
     * mpn fallback). Returns map of sku_key => raw manufacturer string.
     *
     * `protected` so the Pest test can override via an anonymous subclass and
     * skip the real mysqli boundary.
     *
     * stock-separate-not-applicable: both SQL sites inside this method select
     * suppliersku/mpn + manufacturer only — they do not read .stock. The
     * 260609-rie dual-file fix only matters for reads of feeds_products.stock.
     * See PLAN scope decision in the .planning/quick/260609-rie-... directory.
     *
     * @param  array<int, string>  $candidateSkus  lowercase, trimmed
     * @return array<string, string>
     */
    protected function lookupSupplierManufacturers(array $candidateSkus): array
    {
        if ($candidateSkus === []) {
            return [];
        }

        $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);

        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(
            (string) $creds['host'],
            (string) $creds['username'],
            (string) $creds['password'],
            (string) $creds['database'],
            (int) ($creds['port'] ?? 3306),
        );
        if ($db->connect_errno !== 0) {
            throw new \RuntimeException("Supplier DB connect failed (errno={$db->connect_errno}): {$db->connect_error}");
        }

        $escaped = array_map(
            static fn (string $s): string => "'".$db->real_escape_string($s)."'",
            $candidateSkus,
        );
        $inList = implode(',', $escaped);

        $out = [];

        // 1) suppliersku pass.
        $result = $db->query(
            'SELECT LOWER(TRIM(suppliersku)) AS sku_key, manufacturer '
            ."FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(suppliersku)) IN ({$inList})",
            MYSQLI_USE_RESULT,
        );
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $key = (string) $row['sku_key'];
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                $out[$key] = (string) ($row['manufacturer'] ?? '');
            }
            $result->free();
        }

        // 2) mpn pass — only for SKUs still unresolved.
        $remaining = array_values(array_diff($candidateSkus, array_keys($out)));
        if ($remaining !== []) {
            $escapedR = array_map(
                static fn (string $s): string => "'".$db->real_escape_string($s)."'",
                $remaining,
            );
            $inListR = implode(',', $escapedR);
            $result = $db->query(
                'SELECT LOWER(TRIM(mpn)) AS sku_key, manufacturer '
                ."FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(mpn)) IN ({$inListR})",
                MYSQLI_USE_RESULT,
            );
            if ($result !== false) {
                while ($row = $result->fetch_assoc()) {
                    $key = (string) $row['sku_key'];
                    if ($key === '' || isset($out[$key])) {
                        continue;
                    }
                    $out[$key] = (string) ($row['manufacturer'] ?? '');
                }
                $result->free();
            }
        }

        $db->close();

        return $out;
    }
}
