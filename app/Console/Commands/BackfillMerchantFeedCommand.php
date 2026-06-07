<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\NormalisesEan;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260607-cgd — products:backfill-merchant-feed.
 *
 * Lifts the 89% Google Merchant Center disapproval rate on live products
 * (3,493 TRIPLE FAIL rows — no EAN + no brand_id + no category_id) by pulling
 * EAN + manufacturer from supplier_db.feeds_products and chaining Claude
 * category assignment via products:assign-taxonomy.
 *
 * Three field paths, all idempotent (WHERE clauses exclude already-populated rows):
 *   --field=ean       supplier_db.ean → NormalisesEan trait → products.ean
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
        {--no-confirm : Skip interactive y/N confirmations (use for cron / non-interactive runs)}';

    protected $description = 'Backfill EAN/brand/category from supplier_db onto live products to lift Google Merchant Center disapproval rate.';

    /**
     * Captured at perform() start so the auth context is preserved across
     * nested Artisan::call() chains. Null for cron / queue runs.
     */
    private ?User $triggeringUser = null;

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
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
        $noConfirm = (bool) $this->option('no-confirm') || ! $this->input->isInteractive();

        $this->info('Fields requested: '.implode(', ', $fields).($dryRun ? '  (dry-run)' : '  (LIVE)'));

        $updatedSkus = [];

        if (in_array('ean', $fields, true)) {
            $this->newLine();
            $this->info('=== EAN backfill ===');
            $this->backfillEan($dryRun, $skusFilter, $limit, $updatedSkus);
        }

        if (in_array('brand', $fields, true)) {
            $this->newLine();
            $this->info('=== Brand backfill ===');
            $this->backfillBrand($dryRun, $skusFilter, $limit, $updatedSkus);
        }

        if (in_array('category', $fields, true)) {
            $this->newLine();
            $this->info('=== Category backfill ===');
            $aborted = $this->backfillCategory($dryRun, $skusFilter, $limit, $noConfirm, $updatedSkus);
            if ($aborted) {
                return SymfonyCommand::FAILURE;
            }
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
     * looks up the supplier EAN per SKU, classifies into 4 quadrants, prints
     * counts + 20-row sample, and on live writes only validated rows.
     *
     * @param  array<int, string>  $skusFilter
     * @param  array<int, string>  &$updatedSkus  populated with successfully-updated SKUs
     */
    private function backfillEan(bool $dryRun, array $skusFilter, int $limit, array &$updatedSkus): void
    {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('ean')->orWhere('ean', '');
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
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

        // Classify each candidate into one of four outcomes.
        $would = [];
        $skippedInvalid = [];
        $skippedNoMatch = [];
        $alreadyPopulated = []; // empty by construction — WHERE clause excludes
        $sample = [];

        foreach ($candidateSkus as $sku) {
            $key = strtolower(trim($sku));
            if (! array_key_exists($key, $supplierEanBySku)) {
                $skippedNoMatch[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, '(no supplier row)', 'skipped_no_supplier_match'];
                }

                continue;
            }
            $rawEan = $supplierEanBySku[$key];
            $validated = $this->normaliseEan($rawEan);
            if ($validated === null) {
                $skippedInvalid[] = $sku;
                if (count($sample) < 20) {
                    $sample[] = [$sku, (string) $rawEan, 'skipped_invalid_ean'];
                }

                continue;
            }
            $would[$sku] = $validated;
            if (count($sample) < 20) {
                $sample[] = [$sku, $validated, $dryRun ? 'would_update' : 'updated'];
            }
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['would_update', count($would)],
                ['skipped_invalid_ean', count($skippedInvalid)],
                ['skipped_no_supplier_match', count($skippedNoMatch)],
                ['already_populated_excluded', count($alreadyPopulated)],
            ],
        );

        $this->newLine();
        $this->info('Sample (first 20):');
        $this->table(['SKU', 'Candidate EAN', 'Outcome'], $sample);

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run — exiting EAN pass without writes.');

            return;
        }

        // Live path: chunk into 500-row batches.
        $batches = array_chunk($would, 500, true);
        $updated = 0;
        foreach ($batches as $batch) {
            foreach ($batch as $sku => $ean) {
                $affected = DB::table('products')
                    ->where('sku', $sku)
                    ->update(['ean' => $ean, 'updated_at' => now()]);
                if ($affected > 0) {
                    $updatedSkus[] = $sku;
                    $updated++;
                }
            }
        }
        $this->info("Updated {$updated} product(s) with EAN.");
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
     */
    private function backfillBrand(bool $dryRun, array $skusFilter, int $limit, array &$updatedSkus): void
    {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('brand_id')->orWhere('brand_id', 0);
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
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
     */
    private function backfillCategory(
        bool $dryRun,
        array $skusFilter,
        int $limit,
        bool $noConfirm,
        array &$updatedSkus,
    ): bool {
        $query = Product::query()
            ->where('status', 'publish')
            ->where(function ($q): void {
                $q->whereNull('category_id')->orWhere('category_id', 0);
            });
        if ($skusFilter !== []) {
            $query->whereIn('sku', $skusFilter);
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
