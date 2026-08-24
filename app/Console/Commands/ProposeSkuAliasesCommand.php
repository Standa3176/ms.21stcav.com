<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260823-clp — propose alternative supplier SKUs.
 *
 * The supplier DB already knows which codes belong to the same physical part:
 * feeds_products carries `mpn` and `suppliersku` as separate columns, so every
 * distinct suppliersku sharing one mpn IS an alternative code. The app has been
 * discarding that relation, which is how duplicate products got created.
 *
 * This walks the feed, matches parts to local products, and reports every
 * supplier code that is NOT already the product's own SKU or a recorded alias.
 *
 * DRY-RUN BY DEFAULT — prints the proposals and writes nothing. `--apply`
 * persists them as source=derived_mpn.
 *
 * Deviation from the 2026-08-09 TODO, which proposed writing these as
 * Suggestions for confirmation: a new Suggestion kind needs an applier plus
 * inbox wiring and buys no extra safety, since the operator reviews the same
 * list either way. Recorded in the 260823-clp PLAN.
 *
 * Matching is deliberately CONSERVATIVE — exact normalised mpn only. Region-
 * suffix stripping (9C941AA vs 9C941AA#ABU) sits behind --strip-suffix because
 * it is the single biggest duplicate source but is not always safe: a suffix
 * sometimes denotes a genuinely different part (bundle, region kit).
 *
 *   php artisan products:propose-sku-aliases
 *   php artisan products:propose-sku-aliases --strip-suffix
 *   php artisan products:propose-sku-aliases --apply
 */
class ProposeSkuAliasesCommand extends BaseCommand
{
    /** Region/variant suffixes seen on prod feeds (260709-db5 precedent). */
    private const SUFFIX_PATTERN = '/#[A-Z0-9]{2,4}$/i';

    private const REPORT_CAP = 40;

    protected $signature = 'products:propose-sku-aliases
        {--apply : Persist the proposals (default: report only, write nothing)}
        {--strip-suffix : Also match when part numbers differ only by a region suffix (#ABU, #ABB, ...)}
        {--limit=0 : Cap proposals (0 = unbounded)}';

    protected $description = 'Propose alternative supplier SKUs from the supplier feed so second-supplier codes stop creating duplicate products (260823-clp).';

    public function __construct(private readonly IntegrationCredentialResolver $resolver)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $apply = (bool) $this->option('apply');
        $stripSuffix = (bool) $this->option('strip-suffix');
        $limit = max(0, (int) $this->option('limit'));

        // ── Local identity: product SKU + already-known aliases ─────────────
        $skuToProductId = [];
        foreach (Product::query()->whereNotNull('sku')->get(['id', 'sku']) as $p) {
            $key = ProductSupplierSku::normalise((string) $p->sku);
            if ($key !== '') {
                $skuToProductId[$key] = (int) $p->id;
            }
        }

        if ($skuToProductId === []) {
            $this->warn('No local products with a SKU — nothing to match against.');

            return SymfonyCommand::SUCCESS;
        }

        $known = ProductSupplierSku::normalisedMap();

        // Suffix-stripped index, built only when asked for.
        $baseToProductId = [];
        if ($stripSuffix) {
            foreach ($skuToProductId as $key => $id) {
                $base = (string) preg_replace(self::SUFFIX_PATTERN, '', $key);
                if ($base !== '') {
                    $baseToProductId[$base] ??= $id;
                }
            }
        }

        $rows = $this->fetchFeedRows();
        if ($rows === null) {
            return SymfonyCommand::FAILURE;
        }

        $proposals = [];
        $seen = [];
        $scanned = 0;

        foreach ($rows as $row) {
            $scanned++;
            $mpn = ProductSupplierSku::normalise((string) ($row['mpn'] ?? ''));
            $supplierSku = trim((string) ($row['suppliersku'] ?? ''));
            $aliasKey = ProductSupplierSku::normalise($supplierSku);

            if ($aliasKey === '' || $mpn === '') {
                continue;
            }

            // Which local product does this part belong to?
            $productId = $skuToProductId[$mpn] ?? $skuToProductId[$aliasKey] ?? null;
            if ($productId === null && $stripSuffix) {
                $productId = $baseToProductId[(string) preg_replace(self::SUFFIX_PATTERN, '', $mpn)] ?? null;
            }
            if ($productId === null) {
                // A part we don't stock — that is the add-candidate scan's job.
                continue;
            }

            // Already the product's own SKU, already recorded, or already proposed.
            if (isset($skuToProductId[$aliasKey]) || isset($known[$aliasKey]) || isset($seen[$aliasKey])) {
                continue;
            }

            $seen[$aliasKey] = true;
            $proposals[] = [
                'product_id' => $productId,
                'supplier_id' => ((int) ($row['supplierid'] ?? 0)) ?: null,
                'supplier_sku' => $supplierSku,
                'mpn' => trim((string) ($row['mpn'] ?? '')),
            ];

            if ($limit > 0 && count($proposals) >= $limit) {
                break;
            }
        }

        if ($proposals === []) {
            $this->info("Scanned {$scanned} feed rows — no new alternative SKUs to propose.");

            return SymfonyCommand::SUCCESS;
        }

        $this->renderProposals($proposals);

        if (! $apply) {
            $this->newLine();
            $this->info(count($proposals).' proposal(s). Nothing written — re-run with --apply to persist them.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Wrote {$this->persist($proposals)} alternative SKU(s) (source=derived_mpn).");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Distinct (mpn, suppliersku, supplier) triples from the supplier feed.
     *
     * @return array<int, array<string, mixed>>|null  null on a connection/query failure
     */
    private function fetchFeedRows(): ?array
    {
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
            $this->error("Supplier DB connect failed (errno={$db->connect_errno}): {$db->connect_error}");

            return null;
        }

        // stock-separate-not-applicable: this query selects mpn / suppliersku /
        // supplierid only — it never reads fp.stock, so the 260609-rie
        // stockseparate LEFT JOIN (Ingram stores stock in a separate table)
        // has nothing to contribute. Alias proposal is an identity question,
        // not an availability one.
        $sql = 'SELECT DISTINCT TRIM(mpn) AS mpn, TRIM(suppliersku) AS suppliersku, supplierid
                FROM feeds_products
                WHERE product_excluded = 0
                  AND TRIM(mpn) <> ""
                  AND TRIM(suppliersku) <> ""';

        $result = $db->query($sql);
        if ($result === false) {
            $err = $db->error;
            $db->close();
            $this->error("Feed query failed: {$err}");

            return null;
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $db->close();

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function renderProposals(array $proposals): void
    {
        $this->newLine();
        $this->table(
            ['Product', 'Local SKU', 'Alternative SKU', 'Supplier', 'via MPN'],
            array_map(function (array $p): array {
                return [
                    (string) $p['product_id'],
                    (string) (Product::find($p['product_id'])->sku ?? '?'),
                    (string) $p['supplier_sku'],
                    (string) ($p['supplier_id'] ?? 'any'),
                    (string) $p['mpn'],
                ];
            }, array_slice($proposals, 0, self::REPORT_CAP)),
        );

        $more = count($proposals) - self::REPORT_CAP;
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function persist(array $proposals): int
    {
        $written = 0;

        foreach ($proposals as $p) {
            // Keyed on the unique pair so a re-run is a no-op rather than a
            // constraint violation.
            $alias = ProductSupplierSku::firstOrCreate(
                [
                    'normalised_sku' => ProductSupplierSku::normalise((string) $p['supplier_sku']),
                    'supplier_id' => $p['supplier_id'],
                ],
                [
                    'product_id' => $p['product_id'],
                    'supplier_sku' => $p['supplier_sku'],
                    'source' => ProductSupplierSku::SOURCE_DERIVED_MPN,
                    'confidence' => 90,
                    'notes' => 'Derived from feed MPN '.$p['mpn'],
                ],
            );

            if ($alias->wasRecentlyCreated) {
                $written++;
            }
        }

        return $written;
    }
}
