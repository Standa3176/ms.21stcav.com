<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Concerns\JoinsStockSeparate;
use App\Domain\Sync\Services\SupplierExclusionResolver;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260504-m5w — supplier:db-sync.
 *
 * Phase 2 of the remote supplier mirror. Where Phase 1's TestIntegrationAction
 * proves auth + reachability, this command does the actual data pull: connects
 * to the remote supplier MySQL VPS via the SupplierDb integration credential,
 * queries the per-supplier feeds_products table on stcav_dash for rows matching
 * local Woo SKUs, and updates products.buy_price + stock_quantity on each match.
 *
 * Match key precedence:
 *   1. LOWER(TRIM(mpn))         — manufacturer part number (preferred)
 *   2. LOWER(TRIM(suppliersku)) — supplier internal SKU (fallback)
 *
 * buy_price selection (2026-05-25 fix): a SKU is usually carried by MULTIPLE
 * suppliers at different prices + stock. We must buy at the CHEAPEST IN-STOCK
 * supplier, so buildBestOfferMap picks min(price) among offers with stock>0,
 * falling back to min(price) overall only when nothing is in stock. (The old
 * code read supplier_products ORDER BY updated_at DESC — the latest-updated
 * row, ignoring price + stock — which mis-costed multi-supplier SKUs, e.g. a
 * part costing 42p in-stock at Ingram was recorded at £4.78 from out-of-stock
 * Westcoast.) stock_quantity is the SUM across in-stock suppliers (total we can
 * source). feeds_products is the per-supplier table (one row per supplier ×
 * SKU); supplier_products is the remote's own deduped winner table, whose
 * dedup rule is NOT cheapest-in-stock, so we bypass it.
 *
 * 260608-g8x extension: buildBestOfferMap also drops offers from STALE
 * suppliers (no upload for ≥ threshold_days) BEFORE the cheapest-in-stock
 * reduction. Same cheapest-in-stock rule for the remaining FRESH offers.
 * Centralised classification in SupplierFreshnessResolver — flip the policy
 * in ONE file. Disable via constructor flag `excludeStaleSuppliersFromBuyPrice
 * = false` (back-compat). Safe to default ON: on the very first run after
 * deployment, every supplier with a today() snapshot classifies as fresh.
 *
 * 260626-oqr extension: buildBestOfferMap ALSO drops offers from operator-
 * EXCLUDED suppliers (suppliers.is_active=false) — price AND stock — ahead of
 * the stale filter. This drop is UNCONDITIONAL: it is NOT gated by
 * excludeStaleSuppliersFromBuyPrice. An explicit operator exclusion (set via
 * the Filament Suppliers page) outranks the freshness policy and has no OFF
 * switch. Sourced from the SupplierExclusionResolver singleton. A SKU only an
 * excluded supplier carried yields no offer (key absent), flowing through the
 * existing no-fresh-source handling — same behaviour as all-stale.
 *
 * Always filters product_excluded=0. mysqli is used directly (NOT a registered
 * Laravel connection) so this command does not pollute config/database.php for
 * what is a per-run external query.
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
    use JoinsStockSeparate;

    protected $signature = 'supplier:db-sync
        {--dry-run : Report what would change without writing}
        {--limit=0 : Stop after N matches (0 = no limit)}
        {--flag-obsolete : Demote published, non-custom products with NO supplier offer to status=pending (possibly obsolete; review)}';

    protected $description = 'Sync price + stock from the remote supplier MySQL into local products.';

    /**
     * 260626-oqr — operator-excluded supplier resolver. NULLABLE last param,
     * resolved from the container when null, so existing constructions
     * (`new SupplierDbSyncCommand(app(...), app(...), excludeStaleSuppliersFromBuyPrice: $bool)`)
     * stay backward-compatible.
     */
    private readonly SupplierExclusionResolver $exclusion;

    public function __construct(
        private readonly IntegrationCredentialResolver $resolver,
        private readonly SupplierFreshnessResolver $freshness,
        private readonly bool $excludeStaleSuppliersFromBuyPrice = true,
        ?SupplierExclusionResolver $exclusion = null,
    ) {
        parent::__construct();
        $this->exclusion = $exclusion ?? app(SupplierExclusionResolver::class);
    }

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $flagObsolete = (bool) $this->option('flag-obsolete');

        $this->info('supplier:db-sync — '.($dryRun ? 'DRY-RUN' : 'LIVE')
            .($limit > 0 ? " (limit={$limit})" : '')
            .($flagObsolete ? ' [+flag-obsolete]' : ''));

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
            // Pull EVERY supplier's offer (feeds_products) so buildBestOfferMap
            // can pick the cheapest in-stock — not the remote's deduped winner.
            $stockSelect = $this->stockColumnSelect();
            $stockJoin = $this->stockSeparateJoinClause();
            $sql = "SELECT fp.mpn, fp.suppliersku, fp.supplierid, fp.price, {$stockSelect}, f.name AS supplier_name
                    FROM feeds_products fp
                    {$stockJoin}
                    WHERE fp.product_excluded = 0
                      AND (LOWER(TRIM(fp.mpn)) IN ({$placeholders})
                           OR LOWER(TRIM(fp.suppliersku)) IN ({$placeholders}))";

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

        // ── Build best-offer map (cheapest in-stock per key) ──
        $map = $this->buildBestOfferMap($supplierRows);
        $this->info('Built best-offer map: '.count($map).' unique keys (cheapest in-stock) from '.count($supplierRows).' supplier offers.');

        // ── Iterate local Products ──
        $matched = 0;
        $unmatched = 0;
        $updated = 0;
        $unchanged = 0;
        $errored = 0;
        $wouldUpdate = 0;
        $processed = 0;
        $flaggedObsolete = 0;
        $wouldFlagObsolete = 0;

        Product::whereNotNull('sku')->orderBy('id')->chunk(500, function ($batch) use (
            &$matched, &$unmatched, &$updated, &$unchanged, &$errored, &$wouldUpdate,
            &$processed, &$flaggedObsolete, &$wouldFlagObsolete, $map, $dryRun, $limit, $flagObsolete
        ) {
            foreach ($batch as $local) {
                $processed++;
                $key = strtolower(trim((string) $local->sku));
                if ($key === '' || ! isset($map[$key])) {
                    $unmatched++;

                    // No supplier offer → possibly obsolete. With --flag-obsolete,
                    // demote published / non-custom / non-excluded products to
                    // status=pending for review (operator rule 2026-05-25; mirrors
                    // MarkMissingSkusJob on the supplier_api path).
                    if ($flagObsolete && $key !== '' && $this->isObsoleteCandidate($local)) {
                        if ($dryRun) {
                            $wouldFlagObsolete++;
                        } else {
                            Product::where('id', $local->id)->update(['status' => 'pending']);
                            $flaggedObsolete++;
                        }
                    }

                    continue;
                }

                $matched++;
                $row = $map[$key];

                // Pre-computed by buildBestOfferMap: cheapest IN-STOCK price
                // (fallback cheapest overall) + total stock across suppliers.
                $newBuy = $row['buy'];     // string|null (parsePrice form)
                $newStock = $row['stock']; // int|null

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

        if ($flagObsolete) {
            $this->warn(sprintf(
                $dryRun
                    ? 'Obsolete (no supplier offer): %d published/non-custom products WOULD be demoted to status=pending.'
                    : 'Obsolete (no supplier offer): %d published/non-custom products demoted to status=pending for review.',
                $dryRun ? $wouldFlagObsolete : $flaggedObsolete,
            ));
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * A published, supplier-sourced simple product the feed no longer carries —
     * an obsolete/discontinued candidate to demote to 'pending' for review.
     * Skips custom-ms (is_custom_ms OR 'custom-ms' tag) + manually-excluded
     * products (mirrors FlagProductsMissingBuyPriceCommand + MarkMissingSkusJob
     * carve-outs). Only `publish` rows are touched — never re-demote pending/
     * draft/private.
     */
    public function isObsoleteCandidate(Product $product): bool
    {
        if ((string) $product->status !== 'publish') {
            return false;
        }
        if ((bool) $product->is_custom_ms === true) {
            return false;
        }
        if ((bool) $product->exclude_from_auto_update === true) {
            return false;
        }

        return ! in_array('custom-ms', (array) ($product->tags ?? []), true);
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
            $stockSelect = $this->stockColumnSelect();
            $stockJoin = $this->stockSeparateJoinClause();
            $sql = "SELECT fp.mpn, fp.suppliersku, fp.supplierid, fp.price, {$stockSelect}, fp.rrp, f.name AS supplier_name
                    FROM feeds_products fp
                    {$stockJoin}
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
     * Build a lowercased-key lookup map choosing the BEST offer per key across
     * all suppliers. Each supplier offer is indexed under BOTH its mpn and its
     * suppliersku (so a local product matches by either), and per key we keep:
     *
     *   - buy   : cheapest price among IN-STOCK offers (stock > 0); if no offer
     *             is in stock, the cheapest price overall (so we still have a
     *             cost, flagged in_stock=false).
     *   - stock : SUM of stock across in-stock offers (total we can source).
     *   - supplier / in_stock / matched_via : transparency for logging.
     *
     * Replaces the old "first row wins (latest updated_at)" rule, which ignored
     * price + stock and so mis-costed multi-supplier SKUs.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{buy:?string, stock:int, supplier:?string, in_stock:bool, matched_via:string}>
     */
    public function buildBestOfferMap(array $rows): array
    {
        // 260626-oqr — operator-excluded suppliers (suppliers.is_active=false) are
        // dropped UNCONDITIONALLY, ahead of the freshness filter. An explicit
        // operator exclusion outranks freshness policy and is not behind any flag.
        // (e.g. Nuvias paused while it ships stale data.) Same row-shape filter as
        // the stale block below; sourced from the SupplierExclusionResolver singleton.
        $excludedIds = $this->exclusion->excludedSupplierIds()->all();
        if ($excludedIds !== []) {
            $excludedSet = array_flip(array_map('strval', $excludedIds));
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($excludedSet): bool {
                    $sid = isset($row['supplierid']) ? (string) $row['supplierid'] : '';

                    return $sid === '' || ! isset($excludedSet[$sid]);
                },
            ));
        }

        // Quick task 260608-g8x — drop offers belonging to stale suppliers
        // BEFORE the cheapest-in-stock reduction runs. Resolver is per-request
        // cached (singleton) so this is one classify() call per command run.
        // Filter happens in PHP after rows came back from the remote MySQL VPS.
        if ($this->excludeStaleSuppliersFromBuyPrice) {
            $staleIds = $this->freshness->staleSupplierIds()->all();
            if ($staleIds !== []) {
                $staleSet = array_flip(array_map('strval', $staleIds));
                $rows = array_values(array_filter(
                    $rows,
                    static function (array $row) use ($staleSet): bool {
                        $sid = isset($row['supplierid']) ? (string) $row['supplierid'] : '';

                        return $sid === '' || ! isset($staleSet[$sid]);
                    },
                ));
            }
        }

        /** @var array<string, array<string, mixed>> $acc */
        $acc = [];

        foreach ($rows as $row) {
            $priceStr = $this->parsePrice(isset($row['price']) ? (string) $row['price'] : null);
            $price = $priceStr === null ? null : (float) $priceStr;
            $stock = $this->parseStock(isset($row['stock']) ? (string) $row['stock'] : null);
            $supplier = (string) ($row['supplier_name'] ?? $row['supplierid'] ?? '');
            $inStock = $stock !== null && $stock > 0;

            $mpn = strtolower(trim((string) ($row['mpn'] ?? '')));
            $sku = strtolower(trim((string) ($row['suppliersku'] ?? '')));

            foreach ([['mpn', $mpn], ['suppliersku', $sku]] as [$via, $key]) {
                if ($key === '') {
                    continue;
                }
                if (! isset($acc[$key])) {
                    $acc[$key] = [
                        'matched_via' => $via,
                        'instock_price' => null, 'instock_str' => null, 'instock_supplier' => null,
                        'any_price' => null, 'any_str' => null, 'any_supplier' => null,
                        'total_stock' => 0,
                    ];
                }

                if ($inStock) {
                    $acc[$key]['total_stock'] += $stock;
                }

                if ($price !== null && $price > 0) {
                    if ($acc[$key]['any_price'] === null || $price < $acc[$key]['any_price']) {
                        $acc[$key]['any_price'] = $price;
                        $acc[$key]['any_str'] = $priceStr;
                        $acc[$key]['any_supplier'] = $supplier;
                    }
                    if ($inStock && ($acc[$key]['instock_price'] === null || $price < $acc[$key]['instock_price'])) {
                        $acc[$key]['instock_price'] = $price;
                        $acc[$key]['instock_str'] = $priceStr;
                        $acc[$key]['instock_supplier'] = $supplier;
                    }
                }
            }
        }

        $map = [];
        foreach ($acc as $key => $a) {
            $useInStock = $a['instock_price'] !== null;
            $map[$key] = [
                'buy' => $useInStock ? $a['instock_str'] : $a['any_str'],
                'stock' => (int) $a['total_stock'],
                'supplier' => $useInStock ? $a['instock_supplier'] : $a['any_supplier'],
                'in_stock' => $useInStock,
                'matched_via' => $a['matched_via'],
            ];
        }

        return $map;
    }
}
