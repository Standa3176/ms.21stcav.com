<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;

/**
 * Catalogue-expansion scan: parts our suppliers carry that we DON'T sell yet.
 *
 * Reads the remote per-supplier feed (feeds_products on stcav_dash), groups by
 * manufacturer part number (mpn), keeps parts stocked by at least N distinct
 * suppliers (default 2 = reliable supply), and excludes any part we already
 * stock (mpn OR a suppliersku matching a local products.sku). Returns each
 * candidate's brand (manufacturer), part (mpn), description (title) + supplier
 * count — the inputs an operator needs to decide whether to add it.
 *
 * Read-only; never writes Woo. The feed has no category column, so categories
 * are not available here (they're derived via AI only when a product is
 * drafted). Heavy enough (remote GROUP BY over the full feed) that the caller
 * runs it on a schedule + caches the result — never on page load.
 */
final class SupplierAddCandidateScanner
{
    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    /**
     * @return array{
     *   candidates: array<int, array{brand:string, part:string, title:string, suppliers:int}>,
     *   count:int, min_suppliers:int, computed_at:string
     * }
     */
    public function scan(int $minSuppliers = 4, int $listCap = 5000): array
    {
        $minSuppliers = max(2, $minSuppliers);
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

        // Per-mpn aggregation on the remote: ≥N distinct suppliers, with a
        // representative brand/title + the set of supplierskus for exclusion.
        @$db->query('SET SESSION group_concat_max_len = 100000');
        // stock-separate-not-applicable: this query selects mpn / supplier_count
        // / brand / title / supplierskus only (add-candidate aggregation) —
        // does not read .stock. The 260609-rie dual-file fix only matters for
        // reads of feeds_products.stock. See PLAN scope decision in the
        // .planning/quick/260609-rie-... directory.
        $sql = 'SELECT TRIM(mpn) AS mpn,
                       COUNT(DISTINCT supplierid) AS supplier_count,
                       MAX(manufacturer) AS brand,
                       MAX(title) AS title,
                       GROUP_CONCAT(DISTINCT LOWER(TRIM(suppliersku))) AS supplierskus
                FROM feeds_products
                WHERE product_excluded = 0 AND TRIM(mpn) <> \'\'
                GROUP BY TRIM(mpn)
                HAVING COUNT(DISTINCT supplierid) >= ?
                ORDER BY supplier_count DESC, MAX(title) ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $err = $db->error;
            $db->close();
            throw new \RuntimeException("Add-candidate query prepare failed: {$err}");
        }
        $stmt->bind_param('i', $minSuppliers);
        $stmt->execute();
        $result = $stmt->get_result();

        // Local SKUs we already stock (lowercased) — the exclusion set.
        $localSkus = Product::query()
            ->whereNotNull('sku')
            ->pluck('sku')
            ->mapWithKeys(static fn ($s): array => [strtolower(trim((string) $s)) => true])
            ->all();

        $candidates = [];
        $count = 0;

        while ($row = $result->fetch_assoc()) {
            $mpnKey = strtolower(trim((string) $row['mpn']));
            if ($mpnKey === '' || isset($localSkus[$mpnKey])) {
                continue; // already on MS (matched by mpn)
            }
            // Also skip if any of this part's supplier SKUs is a local SKU.
            $onMs = false;
            foreach (explode(',', (string) ($row['supplierskus'] ?? '')) as $ssku) {
                $ssku = trim($ssku);
                if ($ssku !== '' && isset($localSkus[$ssku])) {
                    $onMs = true;
                    break;
                }
            }
            if ($onMs) {
                continue;
            }

            $count++;
            if (count($candidates) < $listCap) {
                $candidates[] = [
                    'brand' => trim((string) ($row['brand'] ?? '')),
                    'part' => trim((string) $row['mpn']),
                    'title' => trim((string) ($row['title'] ?? '')),
                    'suppliers' => (int) $row['supplier_count'],
                ];
            }
        }
        $stmt->close();
        $db->close();

        return [
            'candidates' => $candidates,
            'count' => $count,
            'min_suppliers' => $minSuppliers,
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
