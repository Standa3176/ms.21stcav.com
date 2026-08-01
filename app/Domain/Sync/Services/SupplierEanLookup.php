<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;

/**
 * Quick task 260726-sle — READ-ONLY supplier_db EAN lookup for an arbitrary SKU list.
 *
 * Returns the supplier feed's EAN for any given SKU regardless of local product
 * state — the piece products:backfill-merchant-feed cannot expose because its
 * candidate selection skips SKUs that already carry a (possibly corrupted) local
 * products.ean. Consumed by the supplier:lookup-eans console command.
 *
 * Query + connection semantics are a VERBATIM mirror of
 * BackfillMerchantFeedCommand::lookupSupplierEans() (same credential resolution,
 * same two-pass suppliersku→mpn match, same product_excluded=0 filter, same
 * LOWER(TRIM()) IN (...) shape). Duplicated deliberately rather than extracted so
 * the proven backfill command stays byte-identical (its counts/tests unchanged) —
 * the only addition here is exposing WHICH pass matched (matched_by) plus IN()
 * chunking so a large SKU list can never build a monster query. See the
 * 260726-sle SUMMARY for the extraction-vs-replication rationale.
 *
 * READ-ONLY: only SELECTs against feeds_products; a single connection closed in a
 * finally; no writes anywhere (no supplier_db write, no local products write),
 * no Woo calls. mysqli is used directly (NOT a registered Laravel connection),
 * identical rationale to MysqlSupplierFeedReader / SupplierFeedSourceabilityChecker.
 *
 * stock-separate-not-applicable: both SQL sites select suppliersku/mpn + ean only —
 * they do not read .stock, so the 260609-rie dual-file fix is irrelevant here.
 *
 * Not `final` so the Pest feature test can override lookup() via an anonymous
 * subclass and skip the real mysqli boundary (matches the BackfillMerchantFeed
 * lookupSupplierEans test-double pattern).
 */
class SupplierEanLookup
{
    /** IN() list chunk size — bounds each query so a large SKU list stays safe. */
    private const CHUNK = 500;

    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    /**
     * Look up supplier EANs for the given SKU keys.
     *
     * @param  array<int, string>  $skuKeys  lowercase, trimmed SKU keys
     * @return array<string, array{ean: string, matched_by: string}> keyed by sku_key
     */
    public function lookup(array $skuKeys): array
    {
        $keys = array_values(array_unique(array_filter(
            $skuKeys,
            static fn (string $s): bool => $s !== '',
        )));
        if ($keys === []) {
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

        /** @var array<string, array{ean: string, matched_by: string}> $out */
        $out = [];

        try {
            // 1) suppliersku pass (preferred — supplier catalogue key).
            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                foreach ($this->fetch($db, 'suppliersku', $chunk) as $key => $ean) {
                    if ($key === '' || isset($out[$key])) {
                        continue;
                    }
                    $out[$key] = ['ean' => $ean, 'matched_by' => 'suppliersku'];
                }
            }

            // 2) mpn pass — only for SKUs still unresolved.
            $remaining = array_values(array_diff($keys, array_keys($out)));
            foreach (array_chunk($remaining, self::CHUNK) as $chunk) {
                foreach ($this->fetch($db, 'mpn', $chunk) as $key => $ean) {
                    if ($key === '' || isset($out[$key])) {
                        continue;
                    }
                    $out[$key] = ['ean' => $ean, 'matched_by' => 'mpn'];
                }
            }
        } finally {
            $db->close();
        }

        return $out;
    }

    /**
     * Run one SELECT pass over feeds_products matching $column against the keys.
     *
     * $column is a hard-coded literal ('suppliersku' | 'mpn') from this class only
     * — never user input — so its interpolation carries no injection risk; the
     * key values are real_escape_string-escaped exactly as the backfill command does.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string> sku_key => raw EAN string
     */
    private function fetch(\mysqli $db, string $column, array $keys): array
    {
        $escaped = array_map(
            static fn (string $s): string => "'".$db->real_escape_string($s)."'",
            $keys,
        );
        $inList = implode(',', $escaped);

        $out = [];
        $result = $db->query(
            "SELECT LOWER(TRIM({$column})) AS sku_key, ean "
            ."FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM({$column})) IN ({$inList})",
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

        return $out;
    }
}
