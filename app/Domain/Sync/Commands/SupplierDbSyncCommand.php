<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260504-m5w — supplier:db-sync.
 *
 * Phase 2 of the remote supplier mirror. Where Phase 1's TestIntegrationAction
 * proves auth + reachability, this command does the actual data pull: connects
 * to the remote supplier MySQL VPS via the SupplierDb integration credential,
 * queries supplier_products on stcav_dash for rows matching local Woo SKUs,
 * and updates products.buy_price + stock_quantity on each match.
 *
 * Match key precedence (verified live on the 646,985-row supplier_products
 * table — 68.8% coverage on mpn, 27.2% fallback on suppliersku):
 *   1. LOWER(TRIM(mpn))         — manufacturer part number (preferred)
 *   2. LOWER(TRIM(suppliersku)) — supplier internal SKU (fallback)
 *
 * Always filters product_excluded=0; ORDER BY updated_at DESC so the latest row
 * wins on tie. mysqli is used directly (NOT a registered Laravel connection)
 * so this command does not pollute config/database.php for what is a per-run
 * external query.
 *
 * Operator entry points:
 *   php artisan supplier:db-sync --dry-run               (full match count, no writes)
 *   php artisan supplier:db-sync --dry-run --limit=10    (smoke-test wiring)
 *   php artisan supplier:db-sync                         (LIVE — updates products)
 *
 * Schedule: routes/console.php registers a daily 03:30 Europe/London run after
 * the 03:00 woo:import-products refresh, so any new Woo SKUs land same-day.
 */
final class SupplierDbSyncCommand extends BaseCommand
{
    protected $signature = 'supplier:db-sync
        {--dry-run : Report what would change without writing}
        {--limit=0 : Stop after N matches (0 = no limit)}';

    protected $description = 'Sync price + stock from the remote supplier MySQL into local products.';

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('supplier:db-sync — '.($dryRun ? 'DRY-RUN' : 'LIVE')
            .($limit > 0 ? " (limit={$limit})" : ''));

        // ── Resolve credentials ──
        $creds = $this->resolver->for(IntegrationCredentialKind::SupplierDb);

        // Suppress mysqli's default warning-on-failure so we can return a clean
        // SELF::FAILURE instead of leaking PHP warnings into the command output
        // (mirrors TestIntegrationAction::testSupplierDb pattern).
        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = @new \mysqli(
            (string) $creds['host'],
            (string) $creds['username'],
            (string) $creds['password'],
            (string) $creds['database'],
            (int) ($creds['port'] ?? 3306),
        );

        if ($mysqli->connect_errno !== 0) {
            $this->error("MySQL connect failed (errno={$mysqli->connect_errno}): {$mysqli->connect_error}");

            return SymfonyCommand::FAILURE;
        }

        // ── Pull local SKUs ──
        $localSkus = Product::whereNotNull('sku')->pluck('sku')->all();
        $this->info('Local SKUs to match: '.count($localSkus));

        if ($localSkus === []) {
            $this->warn('No local products with SKUs — nothing to match against.');
            $mysqli->close();

            return SymfonyCommand::SUCCESS;
        }

        // Normalise (lowercase + trim) and dedup.
        $lowered = array_values(array_unique(array_filter(array_map(
            static fn (string $sku): string => strtolower(trim($sku)),
            $localSkus,
        ), static fn (string $s): bool => $s !== '')));

        // ── Chunked remote pull ──
        $supplierRows = [];
        $chunkSize = 2000;
        $chunks = array_chunk($lowered, $chunkSize);
        $this->info('Querying remote in '.count($chunks).' chunk(s) of up to '.$chunkSize.' SKUs each...');

        foreach ($chunks as $chunkIndex => $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT id, mpn, suppliersku, price, stock, updated_at
                    FROM supplier_products
                    WHERE product_excluded = 0
                      AND (LOWER(TRIM(mpn)) IN ({$placeholders})
                           OR LOWER(TRIM(suppliersku)) IN ({$placeholders}))
                    ORDER BY updated_at DESC";

            $stmt = $mysqli->prepare($sql);
            if ($stmt === false) {
                $this->error("Prepare failed on chunk {$chunkIndex}: {$mysqli->error}");
                $mysqli->close();

                return SymfonyCommand::FAILURE;
            }

            // Bind the chunk twice — once per IN clause.
            $params = array_merge($chunk, $chunk);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $supplierRows[] = $row;
            }
            $stmt->close();

            $this->line(sprintf(
                '  Chunk %d/%d: %d local SKUs queried, %d cumulative supplier rows fetched',
                $chunkIndex + 1,
                count($chunks),
                count($chunk),
                count($supplierRows),
            ));
        }

        // ── Build SKU map ──
        $map = $this->buildSkuMap($supplierRows);
        $this->info('Built lookup map: '.count($map).' unique keys (from '.count($supplierRows).' supplier rows).');

        // ── Iterate local Products ──
        $matched = 0;
        $unmatched = 0;
        $updated = 0;
        $unchanged = 0;
        $errored = 0;
        $wouldUpdate = 0;
        $processed = 0;

        Product::whereNotNull('sku')->orderBy('id')->chunk(500, function ($batch) use (
            &$matched, &$unmatched, &$updated, &$unchanged, &$errored, &$wouldUpdate,
            &$processed, $map, $dryRun, $limit
        ) {
            foreach ($batch as $local) {
                $processed++;
                $key = strtolower(trim((string) $local->sku));
                if ($key === '' || ! isset($map[$key])) {
                    $unmatched++;
                    continue;
                }

                $matched++;
                $row = $map[$key];

                $newBuy = $this->parsePrice($row['price'] ?? null);
                $newStock = $this->parseStock($row['stock'] ?? null);

                // Normalise existing values for comparison. buy_price is cast
                // to decimal:4 so it returns "60.0000" on the model — compare
                // numerically not stringly to avoid false diffs.
                $existingBuy = $local->buy_price === null ? null : (string) $local->buy_price;
                $existingStock = $local->stock_quantity;

                $buyChanged = ($newBuy === null && $existingBuy !== null)
                    || ($newBuy !== null && $existingBuy === null)
                    || ($newBuy !== null && $existingBuy !== null && (float) $newBuy !== (float) $existingBuy);

                $stockChanged = $newStock !== $existingStock;

                if (! $buyChanged && ! $stockChanged) {
                    $unchanged++;
                    continue;
                }

                if ($dryRun) {
                    $wouldUpdate++;
                } else {
                    try {
                        Product::where('id', $local->id)->update([
                            'buy_price' => $newBuy,
                            'stock_quantity' => $newStock,
                        ]);
                        $updated++;

                        // Quick task 260504-muq — overwrite today's snapshot
                        // with supplier-current values. The earlier
                        // woo:import-products run wrote the same row with
                        // Woo-side values; supplier feed is more authoritative
                        // for buy_price + stock so we win on tie. Idempotent
                        // via unique(product_id, recorded_at).
                        ProductPriceSnapshot::updateOrCreate(
                            [
                                'product_id' => $local->id,
                                'recorded_at' => today(),
                            ],
                            [
                                'sku' => (string) ($local->sku ?? ''),
                                'woo_status' => (string) ($local->status ?? ''),
                                'sell_price' => $local->sell_price,
                                'buy_price' => $newBuy,
                                'stock_quantity' => $newStock,
                            ],
                        );
                    } catch (QueryException $e) {
                        $errored++;
                        Log::warning('supplier_db_sync.row_failed', [
                            'product_id' => $local->id,
                            'sku' => $local->sku,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($limit > 0 && $matched >= $limit) {
                    return false; // breaks chunk()
                }
            }

            // Page-style progress every 500 (one per chunk).
            $this->line(sprintf(
                '  Processed %d local products — matched=%d unmatched=%d',
                $processed,
                $matched,
                $unmatched,
            ));

            return true;
        });

        // Quick task 260504-muq — write per-supplier offer snapshots BEFORE
        // closing the mysqli handle (the helper reuses it). Skip on --dry-run
        // (would write hundreds of MB of zero-value rows). Pass the same
        // lowercased local SKU array we already deduped above.
        $offerSnapshotsWritten = 0;
        if (! $dryRun) {
            $offerSnapshotsWritten = $this->syncSupplierOfferSnapshots($mysqli, $lowered);
        }

        $mysqli->close();

        // ── Summary ──
        $this->info(str_repeat('-', 60));
        $this->info(sprintf(
            'Done. matched=%d unmatched=%d updated=%d unchanged=%d errored=%d offer_snapshots=%d%s',
            $matched,
            $unmatched,
            $updated,
            $unchanged,
            $errored,
            $offerSnapshotsWritten,
            $dryRun ? " would_update={$wouldUpdate}" : '',
        ));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Quick task 260504-muq — pull every supplier offer for the given local
     * SKU set and write SupplierOfferSnapshot rows. One row per
     * (sku, supplier_id, day) — re-runs on the same day overwrite via
     * unique(sku, supplier_id, recorded_at).
     *
     * Match logic mirrors the main pull (LOWER(TRIM(mpn)) preferred,
     * LOWER(TRIM(suppliersku)) fallback) and joins feeds → name for the
     * human-readable supplier label. Chunked at 2000 SKUs per query to keep
     * the IN-clause manageable.
     *
     * @param  array<int, string>  $localSkusLowercased  pre-normalized lowercase-trimmed local SKUs
     */
    private function syncSupplierOfferSnapshots(\mysqli $db, array $localSkusLowercased): int
    {
        if ($localSkusLowercased === []) {
            return 0;
        }

        // Build the lowercase→product_id lookup once (full table scan; ~5,633 rows
        // is cheap). Used to populate the nullable product_id FK on each offer
        // snapshot so the Filament UI can do `where product_id = ?` lookups.
        $localSkuToProductId = Product::whereNotNull('sku')
            ->pluck('id', 'sku')
            ->mapWithKeys(static fn ($id, $sku) => [strtolower(trim((string) $sku)) => $id])
            ->all();

        $written = 0;

        foreach (array_chunk($localSkusLowercased, 2000) as $chunkIndex => $chunk) {
            $placeholders = rtrim(str_repeat('?,', count($chunk)), ',');
            // feeds_products is the per-supplier offer table (one row per
            // supplier × SKU). supplier_products is the deduped winner table
            // used by the main sync loop above. We want the FULL multi-supplier
            // view here, so query feeds_products + LEFT JOIN feeds for the
            // human-readable supplier name (Rule 1 fix — initial code targeted
            // the wrong table).
            $sql = "SELECT fp.mpn, fp.suppliersku, fp.supplierid, fp.price, fp.stock, fp.rrp, f.name AS supplier_name
                    FROM feeds_products fp
                    LEFT JOIN feeds f ON fp.supplierid = f.id
                    WHERE fp.product_excluded = 0
                      AND (LOWER(TRIM(fp.mpn)) IN ({$placeholders})
                           OR LOWER(TRIM(fp.suppliersku)) IN ({$placeholders}))
                    ORDER BY fp.updated_at DESC";

            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                $this->warn("syncSupplierOfferSnapshots: prepare failed on chunk {$chunkIndex}: {$db->error}");
                continue;
            }

            $params = array_merge($chunk, $chunk);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                // Pick the matching key — prefer mpn, fall back to suppliersku.
                $mpnKey = strtolower(trim((string) ($row['mpn'] ?? '')));
                $skuKey = strtolower(trim((string) ($row['suppliersku'] ?? '')));

                $matchKey = null;
                if ($mpnKey !== '' && isset($localSkuToProductId[$mpnKey])) {
                    $matchKey = $mpnKey;
                } elseif ($skuKey !== '' && isset($localSkuToProductId[$skuKey])) {
                    $matchKey = $skuKey;
                }
                if ($matchKey === null) {
                    continue;
                }

                try {
                    SupplierOfferSnapshot::updateOrCreate(
                        [
                            'sku' => $matchKey,
                            'supplier_id' => (string) ($row['supplierid'] ?? ''),
                            'recorded_at' => today(),
                        ],
                        [
                            'product_id' => $localSkuToProductId[$matchKey] ?? null,
                            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                            'price' => $this->parsePrice((string) ($row['price'] ?? '')),
                            'stock' => $this->parseStock((string) ($row['stock'] ?? '')),
                            'rrp' => $this->parsePrice((string) ($row['rrp'] ?? '')),
                        ],
                    );
                    $written++;
                } catch (QueryException $e) {
                    Log::warning('supplier_db_sync.offer_snapshot_failed', [
                        'sku' => $matchKey,
                        'supplier_id' => $row['supplierid'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $stmt->close();
        }

        return $written;
    }

    /**
     * Parse the supplier price column. Strips currency symbols + commas; returns
     * null for empty / non-numeric input. Public for unit tests.
     */
    public function parsePrice(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($clean === null || $clean === '') {
            return null;
        }

        return is_numeric($clean) ? $clean : null;
    }

    /**
     * Parse the supplier stock column (varchar in the remote table). Strips
     * non-numeric chars, casts to int. Returns null for empty / non-numeric
     * input (e.g. "n/a"). Public for unit tests.
     */
    public function parseStock(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\-]/', '', $raw);
        if ($clean === null || $clean === '' || $clean === '-') {
            return null;
        }

        return is_numeric($clean) ? (int) $clean : null;
    }

    /**
     * Build a lowercased-key lookup map from supplier rows. The caller pre-sorts
     * input by updated_at DESC so the FIRST occurrence of any key wins (latest
     * supplier_products row for that mpn / suppliersku).
     *
     * mpn match takes precedence over suppliersku match: when both keys are
     * non-empty for a single row, the mpn key entry is tagged matched_via='mpn'
     * and the suppliersku key entry is tagged matched_via='suppliersku' — both
     * keys are independently lookup-able by callers.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function buildSkuMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $mpn = strtolower(trim((string) ($row['mpn'] ?? '')));
            $sku = strtolower(trim((string) ($row['suppliersku'] ?? '')));

            // mpn match takes precedence — only set if not already present.
            if ($mpn !== '' && ! isset($map[$mpn])) {
                $map[$mpn] = ['matched_via' => 'mpn'] + $row;
            }
            if ($sku !== '' && ! isset($map[$sku])) {
                $map[$sku] = ['matched_via' => 'suppliersku'] + $row;
            }
        }

        return $map;
    }
}
