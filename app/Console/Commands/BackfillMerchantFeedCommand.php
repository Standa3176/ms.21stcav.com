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
            $this->warn('brand backfill not yet implemented in this task (Task 3 lands it).');
        }

        if (in_array('category', $fields, true)) {
            $this->newLine();
            $this->warn('category backfill not yet implemented in this task (Task 4 lands it).');
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
}
