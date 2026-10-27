<?php

declare(strict_types=1);

namespace App\Domain\Sync\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Concerns\JoinsStockSeparate;
use App\Domain\Sync\Services\ProductSupplierBlockResolver;
use App\Domain\Sync\Services\SupplierCostOutlierGuard;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * supplier:explain-cost {sku} — trace where a product's buy_price comes from.
 *
 * Hits the live per-supplier feeds_products table on stcav_dash for one SKU and
 * prints EVERY supplier's price + stock + updated_at + product_excluded flag,
 * then states which offer the cheapest-in-stock rule picks (matching
 * supplier:db-sync / buildBestOfferMap). Read-only diagnostic — writes nothing.
 *
 * Answers "which supplier are we costing this at, and why?" — and reveals when a
 * cheaper supplier was correctly skipped because it's out of stock or excluded.
 *
 * 261027-duz: operator-blocked (key, supplier) offers show as BLOCKED (reason)
 * and are skipped in the selection; an OUTLIER GUARD warning is printed when
 * supplier:db-sync would hold the cost instead of adopting the cheapest offer.
 *
 *   php artisan supplier:explain-cost 910.0103.900
 */
final class ExplainSupplierCostCommand extends BaseCommand
{
    use JoinsStockSeparate;

    protected $signature = 'supplier:explain-cost {sku : Local product SKU (or mpn/suppliersku) to trace}';

    protected $description = 'Show every supplier offer for a SKU + which one sets buy_price (read-only).';

    public function __construct(private readonly IntegrationCredentialResolver $resolver)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $sku = trim((string) $this->argument('sku'));
        $key = strtolower($sku);

        // Local product (if any) for the current stored value.
        $product = Product::whereRaw('LOWER(TRIM(sku)) = ?', [$key])->first();
        if ($product !== null) {
            $this->info(sprintf(
                'LOCAL  id=%d  sku=%s  buy_price=%s  stock=%s',
                $product->id,
                $product->sku,
                $product->buy_price ?? 'null',
                $product->stock_quantity ?? 'null',
            ));
        } else {
            $this->warn("No local product matches '{$sku}' — checking the supplier feed anyway.");
        }

        // ── Connect to the remote supplier MySQL ──
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
            $this->error("MySQL connect failed (errno={$db->connect_errno}): {$db->connect_error}");

            return SymfonyCommand::FAILURE;
        }

        $stockSelect = $this->stockColumnSelect();
        $stockJoin = $this->stockSeparateJoinClause();
        $sql = "SELECT fp.supplierid, f.name AS supplier_name, fp.mpn, fp.suppliersku,
                       fp.price, {$stockSelect}, fp.rrp, fp.product_excluded, fp.updated_at
                FROM feeds_products fp
                {$stockJoin}
                WHERE LOWER(TRIM(fp.mpn)) = ? OR LOWER(TRIM(fp.suppliersku)) = ?
                ORDER BY CAST(fp.price AS DECIMAL(12,4)) ASC";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $this->error("Prepare failed: {$db->error}");
            $db->close();

            return SymfonyCommand::FAILURE;
        }
        $stmt->bind_param('ss', $key, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        $db->close();

        if ($rows === []) {
            $this->warn("No supplier offers found in feeds_products for '{$sku}'.");

            return SymfonyCommand::SUCCESS;
        }

        // 261027-duz — operator offer blocks (fresh read; this is a one-shot CLI).
        $blocks = app(ProductSupplierBlockResolver::class);
        $blocks->forget();
        $blockReasons = $blocks->blocksFor($key);

        $this->newLine();
        $this->line(sprintf('  %-24s %-10s %-7s %-8s %-11s %s', 'SUPPLIER', 'PRICE', 'STOCK', 'EXCL', 'UPDATED', 'mpn / suppliersku'));
        $this->line('  '.str_repeat('-', 88));

        $cheapestInStock = null; // ['price'=>float,'supplier'=>string,'sid'=>string]
        $cheapestAny = null;
        // 261027-duz — per-supplier min price (in-stock / any) for the outlier guard.
        $instockBySupplier = [];
        $anyBySupplier = [];

        foreach ($rows as $r) {
            $excluded = (int) ($r['product_excluded'] ?? 0) === 1;
            $sid = (string) ($r['supplierid'] ?? '');
            $blocked = $sid !== '' && isset($blockReasons[$sid]);
            $priceClean = preg_replace('/[^0-9.\-]/', '', (string) ($r['price'] ?? ''));
            $price = is_numeric($priceClean) ? (float) $priceClean : null;
            $stockClean = preg_replace('/[^0-9\-]/', '', (string) ($r['stock'] ?? ''));
            $stock = ($stockClean === '' || $stockClean === '-') ? 0 : (int) $stockClean;
            $supplier = (string) ($r['supplier_name'] ?: ('id:'.($r['supplierid'] ?? '?')));

            $this->line(sprintf(
                '  %-24s %-10s %-7s %-8s %-11s %s / %s%s',
                mb_substr($supplier, 0, 24),
                (string) $r['price'],
                (string) $r['stock'],
                $blocked ? 'BLOCKED' : ($excluded ? 'EXCL' : '-'),
                mb_substr((string) ($r['updated_at'] ?? ''), 0, 10),
                $r['mpn'] ?? '',
                $r['suppliersku'] ?? '',
                $blocked ? ' — BLOCKED ('.$blockReasons[$sid].')' : '',
            ));

            // Selection mirrors buildBestOfferMap: skip excluded, operator-blocked
            // (261027-duz) and non-positive price.
            if ($excluded || $blocked || $price === null || $price <= 0) {
                continue;
            }
            $gid = $sid !== '' ? $sid : $supplier;
            if (! isset($anyBySupplier[$gid]) || $price < $anyBySupplier[$gid]) {
                $anyBySupplier[$gid] = $price;
            }
            if ($stock > 0 && (! isset($instockBySupplier[$gid]) || $price < $instockBySupplier[$gid])) {
                $instockBySupplier[$gid] = $price;
            }
            if ($cheapestAny === null || $price < $cheapestAny['price']) {
                $cheapestAny = ['price' => $price, 'supplier' => $supplier, 'sid' => $gid];
            }
            if ($stock > 0 && ($cheapestInStock === null || $price < $cheapestInStock['price'])) {
                $cheapestInStock = ['price' => $price, 'supplier' => $supplier, 'sid' => $gid];
            }
        }

        $this->newLine();
        $chosen = $cheapestInStock ?? $cheapestAny;
        if ($chosen === null) {
            $this->warn('No usable offer (all excluded or zero-priced) — buy_price would be left unset.');
        } else {
            $this->info(sprintf(
                '→ buy_price = %.4f from %s  (%s)',
                $chosen['price'],
                $chosen['supplier'],
                $cheapestInStock !== null ? 'cheapest IN-STOCK' : 'cheapest overall — NOTHING in stock',
            ));
            if ($cheapestInStock === null) {
                $this->line('  (No supplier has stock right now, so we fall back to the cheapest available cost.)');
            }

            // 261027-duz — same like-for-like pool as buildBestOfferMap.
            if ((bool) config('supplier.cost_outlier_guard.enabled', true)) {
                $ratio = (float) config('supplier.cost_outlier_guard.ratio', 3);
                $hit = SupplierCostOutlierGuard::detect(
                    $cheapestInStock !== null ? $instockBySupplier : $anyBySupplier,
                    (string) $chosen['sid'],
                    $ratio,
                );
                if ($hit !== null) {
                    $this->warn(sprintf(
                        'OUTLIER GUARD: cheapest is < 1/%s of median of others (£%.2f) — supplier:db-sync will HOLD the existing buy_price and raise a supplier_cost_outlier issue.',
                        rtrim(rtrim(number_format($ratio, 2, '.', ''), '0'), '.'),
                        $hit['median_of_others'],
                    ));
                }
            }
        }

        if ($blockReasons !== []) {
            $this->line('  Blocked for this key: '.implode(', ', array_map(
                static fn (string $sid, string $reason): string => "{$sid} ({$reason})",
                array_keys($blockReasons),
                array_values($blockReasons),
            )).'  — remove with supplier:block-offer {sku} {supplier} --remove');
        }

        return SymfonyCommand::SUCCESS;
    }
}
