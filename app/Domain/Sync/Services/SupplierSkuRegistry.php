<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Support\Facades\DB;

/**
 * Local membership registry for sourceable supplier SKUs — backs the
 * "On supplier DB" filter on /admin/suggestions.
 *
 * The remote supplier feed (feeds_products) is ~900k rows; loading those
 * into a Laravel cache array + whereIn clause exceeds MySQL's packet
 * size, so we materialise the keys into a local `supplier_sku_cache`
 * table (one column: sku PRIMARY KEY). The filter then runs an EXISTS
 * subquery against it — index-backed and fast at any scale.
 *
 * refresh() truncates the table and bulk-inserts the current feed in
 * chunks of 1,000. Called by supplier:refresh-sku-cache (scheduled
 * Mon-Fri 07:05 London, 5 min after supplier:db-sync) and safe to run
 * by hand any time.
 */
class SupplierSkuRegistry
{
    private const TABLE = 'supplier_sku_cache';

    private const CHUNK_SIZE = 1000;

    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    public function refresh(): int
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
            throw new \RuntimeException("Supplier DB connect failed (errno={$db->connect_errno}): {$db->connect_error}");
        }

        // stock-separate-not-applicable: this query selects mpn_key + ssku_key
        // only (registry build) — does not read .stock. The 260609-rie dual-file
        // fix only matters for reads of feeds_products.stock. See PLAN scope
        // decision in the .planning/quick/260609-rie-... directory.
        $result = $db->query(
            'SELECT DISTINCT LOWER(TRIM(mpn)) AS mpn_key, LOWER(TRIM(suppliersku)) AS ssku_key '
            .'FROM feeds_products WHERE product_excluded = 0',
            MYSQLI_USE_RESULT,
        );
        if ($result === false) {
            $err = $db->error;
            $db->close();
            throw new \RuntimeException("Feed scan failed: {$err}");
        }

        DB::table(self::TABLE)->truncate();

        /** @var array<int, array{sku: string}> $buffer */
        $buffer = [];
        $seen = [];
        $inserted = 0;

        while ($row = $result->fetch_assoc()) {
            foreach ([(string) $row['mpn_key'], (string) $row['ssku_key']] as $k) {
                if ($k === '' || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $buffer[] = ['sku' => mb_substr($k, 0, 191)];
                if (count($buffer) >= self::CHUNK_SIZE) {
                    DB::table(self::TABLE)->insertOrIgnore($buffer);
                    $inserted += count($buffer);
                    $buffer = [];
                }
            }
        }
        if ($buffer !== []) {
            DB::table(self::TABLE)->insertOrIgnore($buffer);
            $inserted += count($buffer);
        }

        $result->free();
        $db->close();

        return $inserted;
    }
}
