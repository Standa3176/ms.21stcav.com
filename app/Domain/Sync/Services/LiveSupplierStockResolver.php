<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Concerns\JoinsStockSeparate;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260702-pes — live per-SKU "cheapest FRESH in-stock offer" resolver.
 *
 * WHY LIVE (not supplier_offer_snapshots): a product created TODAY has no
 * snapshot rows yet (the nightly sync only snapshots SKUs that existed at sync
 * time), so hydrate-stock-from-offers can't help it — it would publish OOS
 * until the next sync+push cycle. This reads feeds_products DIRECTLY so a
 * brand-new SKU gets correct stock at publish.
 *
 * Same rule as SupplierDbSyncCommand::syncSupplierOfferSnapshots:
 *   - feeds_products + stockseparate via JoinsStockSeparate (Ingram is_stock_separate
 *     stock is NOT masked — the whole point of the 260609-rie trait).
 *   - product_excluded = 0.
 *   - match LOWER(TRIM(mpn)) = key OR LOWER(TRIM(suppliersku)) = key.
 *   - gated to fresh supplier_ids (SupplierFreshnessResolver) — a stale feed
 *     (e.g. Nuvias) never asserts stock.
 *   - cheapest by price among rows with resolved stock > 0.
 *
 * Best-effort: NEVER throws to the caller. Any failure (blank sku, no fresh
 * suppliers, unreachable supplier_db, prepare/exec error) returns null so a
 * publish is never blocked over stock.
 *
 * NOT final — Pest binds a Mockery double via the container (mirrors
 * HydrateProductStockFromOffersCommand's "not final so tests can swap").
 */
class LiveSupplierStockResolver
{
    use JoinsStockSeparate;

    public function __construct(
        private readonly IntegrationCredentialResolver $creds,
        private readonly SupplierFreshnessResolver $freshness,
    ) {}

    /**
     * @return array{stock_quantity:int, stock_status:string, buy_price:?float}|null
     */
    public function resolveForSku(string $sku): ?array
    {
        $key = strtolower(trim($sku));
        if ($key === '') {
            return null;
        }
        $freshIds = array_values($this->freshness->freshSupplierIds()->all());
        if ($freshIds === []) {
            return null;
        }
        $rows = $this->fetchOfferRows($key, $freshIds);
        if ($rows === null) {
            return null;
        }

        return $this->pickCheapestInStock($rows);
    }

    /**
     * Quick task 260713-rsp — is this SKU LISTED by any FRESH supplier (any
     * stock level, product_excluded=0)? This is the stock-agnostic membership
     * signal — the consistent inverse of supplier:db-sync --flag-obsolete's
     * KEEP decision (a product stays published iff a fresh, non-excluded
     * supplier lists it, regardless of stock). resolveForSku() is the STRICTER
     * in-stock (stock>0) variant; this is the broader opt-in used by
     * products:restore-sourceable-pending --include-listed-out-of-stock.
     *
     * Best-effort: any failure (blank sku, no fresh suppliers, unreachable
     * supplier_db) returns false so a restore is never made on a guess.
     */
    public function isListedByFreshSupplier(string $sku): bool
    {
        $key = strtolower(trim($sku));
        if ($key === '') {
            return false;
        }
        $freshIds = array_values($this->freshness->freshSupplierIds()->all());
        if ($freshIds === []) {
            return false;
        }
        $rows = $this->fetchOfferRows($key, $freshIds);

        return $rows !== null && $rows !== [];
    }

    /**
     * PURE — cheapest offer with resolved stock > 0. Input rows carry
     * 'price' (string|numeric) and 'stock' (string|int, already stockseparate-
     * resolved by the SQL). Null when nothing is in stock.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array{stock_quantity:int, stock_status:string, buy_price:?float}|null
     */
    public function pickCheapestInStock(array $rows): ?array
    {
        $inStock = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (int) ($r['stock'] ?? 0) > 0,
        ));
        if ($inStock === []) {
            return null;
        }
        usort(
            $inStock,
            static fn (array $a, array $b): int => ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0)),
        );
        $best = $inStock[0];

        return [
            'stock_quantity' => (int) $best['stock'],
            'stock_status' => 'instock',
            'buy_price' => is_numeric($best['price'] ?? null) ? (float) $best['price'] : null,
        ];
    }

    /**
     * PURE — parameterized SQL. $freshCount '?' placeholders for supplier ids,
     * then two '?' for the match key (mpn, suppliersku). Uses the trait
     * fragments so the StockSeparateJoinTest guard passes AND Ingram stock is
     * resolved from stockseparate.
     */
    public function buildOfferSql(int $freshCount): string
    {
        $stockSelect = $this->stockColumnSelect();          // "...COALESCE(...) AS stock"
        $stockJoin = $this->stockSeparateJoinClause();      // feeds + stockseparate joins
        $in = rtrim(str_repeat('?,', max(1, $freshCount)), ',');

        return "SELECT fp.mpn, fp.suppliersku, fp.supplierid, fp.price, {$stockSelect}, fp.rrp
                FROM feeds_products fp
                {$stockJoin}
                WHERE fp.product_excluded = 0
                  AND fp.supplierid IN ({$in})
                  AND (LOWER(TRIM(fp.mpn)) = ? OR LOWER(TRIM(fp.suppliersku)) = ?)
                ORDER BY fp.updated_at DESC";
    }

    /**
     * Connect supplier_db (mysqli — same path as generate-drafts / db-sync),
     * run buildOfferSql, return rows or null on any failure.
     *
     * @param  array<int,string>  $freshIds
     * @return array<int, array<string,mixed>>|null
     */
    private function fetchOfferRows(string $key, array $freshIds): ?array
    {
        try {
            $c = $this->creds->for(IntegrationCredentialKind::SupplierDb);
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = @new \mysqli(
                (string) $c['host'], (string) $c['username'], (string) $c['password'],
                (string) $c['database'], (int) ($c['port'] ?? 3306),
            );
            if ($m->connect_errno !== 0) {
                Log::warning('live_stock.connect_failed', ['error' => $m->connect_error]);

                return null;
            }
            $stmt = $m->prepare($this->buildOfferSql(count($freshIds)));
            if ($stmt === false) {
                Log::warning('live_stock.prepare_failed', ['error' => $m->error]);
                $m->close();

                return null;
            }
            $params = array_merge(array_map('strval', $freshIds), [$key, $key]);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $m->close();

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('live_stock.query_failed', ['sku' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
