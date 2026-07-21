<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;

/**
 * Quick task 260719-mgp — live mysqli implementation of {@see SupplierFeedReader}.
 *
 * Connects to the remote supplier MySQL VPS via the SupplierDb integration
 * credential (same pattern as SupplierFeedSourceabilityChecker /
 * SupplierAddCandidateScanner / SupplierDbSyncCommand) and pulls the
 * mpn + suppliersku for every feed row whose manufacturer matches, bounded by a
 * hard row cap.
 *
 * READ-ONLY: a single SELECT with product_excluded = 0, LIKE on manufacturer,
 * and a LIMIT. No writes anywhere; no Woo calls. The remote box is separate from
 * the shop+app server, so this does not load the incident host — but the query
 * is still bounded (cap + prefix match) and the probe dedupes per-manufacturer
 * fetches so total remote queries ≈ distinct manufacturers in the sample.
 *
 * mysqli is used directly (NOT a registered Laravel connection) so this does not
 * pollute config/database.php for what is a per-run external query — identical
 * rationale to the other Sync feed readers.
 */
final class MysqlSupplierFeedReader implements SupplierFeedReader
{
    public function __construct(private readonly IntegrationCredentialResolver $resolver) {}

    public function rowsForManufacturer(string $manufacturer, int $cap = 5000): array
    {
        $manufacturer = mb_strtolower(trim($manufacturer));
        if ($manufacturer === '') {
            return [];
        }
        $cap = max(1, $cap);

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

        // Prefix match so a clean brand token also captures "Brand - Category"
        // feed manufacturers. LIKE special chars escaped so a brand containing
        // % or _ cannot widen the scan. Bounded by LIMIT — the probe caps this.
        //
        // stock-separate-not-applicable: selects mpn + suppliersku only
        // (identity match) — does not read .stock, so the 260609-rie dual-file
        // fix is irrelevant here (same note as SupplierFeedSourceabilityChecker).
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $manufacturer).'%';

        $sql = 'SELECT mpn, suppliersku FROM feeds_products '
            .'WHERE product_excluded = 0 AND LOWER(TRIM(manufacturer)) LIKE ? '
            .'LIMIT ?';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $err = $db->error;
            $db->close();
            throw new \RuntimeException("Feed manufacturer query prepare failed: {$err}");
        }

        $stmt->bind_param('si', $like, $cap);
        $stmt->execute();
        $result = $stmt->get_result();

        /** @var array<int, array{mpn: string, suppliersku: string}> $rows */
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'mpn' => (string) ($row['mpn'] ?? ''),
                'suppliersku' => (string) ($row['suppliersku'] ?? ''),
            ];
        }

        $stmt->close();
        $db->close();

        return $rows;
    }
}
