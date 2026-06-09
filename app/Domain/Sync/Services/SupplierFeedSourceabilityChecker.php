<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;

/**
 * Given a set of candidate match-keys (lowercased/trimmed part identifiers),
 * returns the subset that ANY supplier currently carries — i.e. is "sourceable".
 * Reads the remote per-supplier feed (feeds_products on stcav_dash) the same way
 * SupplierAddCandidateScanner does, matching against BOTH the mpn and suppliersku
 * columns because a competitor part may align with either.
 *
 * Used by the Pricing SourcingGapScanner to split competitor-only parts into
 * sourcing gaps (no supplier carries them — likely obsolete) vs. genuine add
 * opportunities (a supplier does carry them).
 *
 * Read-only; never writes Woo. One unbuffered streaming scan over the feed keeps
 * client memory flat regardless of feed size; we early-exit once every wanted key
 * has been matched. Heavy enough that callers run it on a schedule + cache — never
 * on page load. Lives in Sync because Sync owns the Integrations credential (the
 * Pricing caller can't reach Integrations directly — deptrac).
 *
 * Not `final` so tests can substitute a fixed sourceable set without a live
 * supplier-DB connection (the SourcingGapScanner takes it via constructor DI).
 */
class SupplierFeedSourceabilityChecker
{
    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    /**
     * @param  array<int, string>  $keys  candidate match-keys
     * @return array<string, true> the subset present in feeds_products, as a lookup set keyed by lowercased/trimmed key
     */
    public function sourceableKeys(array $keys): array
    {
        /** @var array<string, true> $wanted */
        $wanted = [];
        foreach ($keys as $k) {
            $k = strtolower(trim((string) $k));
            if ($k !== '') {
                $wanted[$k] = true;
            }
        }
        if ($wanted === []) {
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

        // Unbuffered stream: the feed is large, but we only ever hold the matched
        // subset (bounded by $wanted) in memory, not the whole feed client-side.
        //
        // stock-separate-not-applicable: this query selects mpn_key + ssku_key
        // only (existence/sourceability check) — does not read .stock. The
        // 260609-rie dual-file fix only matters for reads of
        // feeds_products.stock. See PLAN scope decision in the
        // .planning/quick/260609-rie-... directory.
        $result = $db->query(
            'SELECT LOWER(TRIM(mpn)) AS mpn_key, LOWER(TRIM(suppliersku)) AS ssku_key '
            .'FROM feeds_products WHERE product_excluded = 0',
            MYSQLI_USE_RESULT,
        );
        if ($result === false) {
            $err = $db->error;
            $db->close();
            throw new \RuntimeException("Sourceability scan query failed: {$err}");
        }

        /** @var array<string, true> $found */
        $found = [];
        $target = count($wanted);

        while ($row = $result->fetch_assoc()) {
            foreach ([(string) $row['mpn_key'], (string) $row['ssku_key']] as $k) {
                if ($k !== '' && isset($wanted[$k]) && ! isset($found[$k])) {
                    $found[$k] = true;
                    if (count($found) === $target) {
                        break 2; // every wanted key is sourceable — stop early
                    }
                }
            }
        }

        $result->free();
        $db->close();

        return $found;
    }
}
