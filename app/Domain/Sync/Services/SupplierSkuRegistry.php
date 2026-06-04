<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Cached membership registry for sourceable supplier SKUs.
 *
 * Streams the remote supplier feeds_products table once per CACHE_TTL_SECONDS
 * and stores a flat list of lowercased mpn + suppliersku keys in the Laravel
 * cache so Filament filter callbacks don't re-scan the feed on every page
 * render. Refresh is also wired to the scheduler post-supplier-sync.
 *
 * Read-only. Cache hot path returns an array<int, string> (lowercased,
 * deduped). Callers that need an O(1) lookup should array_flip() the result
 * (membership-test friendly: `isset($flipped['sku'])`).
 */
class SupplierSkuRegistry
{
    private const CACHE_KEY = 'supplier.sourceable_skus';

    private const CACHE_TTL_SECONDS = 90000;

    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    /** @return array<int, string> */
    public function allSourceableKeys(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => $this->scanFeed());
    }

    public function refresh(): int
    {
        $keys = $this->scanFeed();
        Cache::put(self::CACHE_KEY, $keys, self::CACHE_TTL_SECONDS);

        return count($keys);
    }

    /** @return array<int, string> */
    private function scanFeed(): array
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

        /** @var array<string, true> $seen */
        $seen = [];
        while ($row = $result->fetch_assoc()) {
            foreach ([(string) $row['mpn_key'], (string) $row['ssku_key']] as $k) {
                if ($k !== '') {
                    $seen[$k] = true;
                }
            }
        }
        $result->free();
        $db->close();

        return array_keys($seen);
    }
}
