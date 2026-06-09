<?php

declare(strict_types=1);

use App\Domain\Sync\Commands\ExplainSupplierCostCommand;
use App\Domain\Sync\Concerns\JoinsStockSeparate;

/**
 * 260609-rie — stockseparate JOIN coverage for ExplainSupplierCostCommand.
 *
 * Boundary strategy: this command renders its table inline in perform() —
 * there's no clean seam to feed synthetic rows without going through the live
 * mysqli boundary. Per the PLAN's Task 5 action note: "if no clean hook
 * exists, assert directly on the trait-emitted SQL." That's exactly what we
 * do here. The row-render path is exercised by ops manually
 * (`php artisan supplier:explain-cost <sku>`) — too tightly coupled to
 * console output to assert cleanly without snapshot-testing the rendered
 * table, which is brittle.
 *
 * Cases A–E mirror the same scope-decision invariants as the SupplierDbSync
 * test, but framed against the SQL fragments emitted by the trait:
 *   A — SELECT fragment resolves stock from stockseparate when is_stock_separate=1
 *   B — SELECT fragment falls through to fp.stock when is_stock_separate=0
 *   C — SELECT fragment COALESCEs NULL → 0
 *   D — JOIN gates stockseparate JOIN behind f.is_stock_separate=1
 *   E — JOIN normalises SKU match via LOWER(TRIM(...))
 */
/**
 * Anonymous helper that uses the JoinsStockSeparate trait directly to expose
 * the protected SELECT/JOIN fragments — ExplainSupplierCostCommand is `final`
 * so we can't subclass it. Drift guard: a separate assertion below confirms
 * the command class actually `use JoinsStockSeparate;`.
 */
function makeExplainSupplierCostExposed(): object
{
    return new class {
        use JoinsStockSeparate;

        public function exposeSelect(): string
        {
            return $this->stockColumnSelect();
        }

        public function exposeJoin(): string
        {
            return $this->stockSeparateJoinClause();
        }
    };
}

// ─── Case A — SELECT fragment picks ss.stock when is_stock_separate=1 ────────

it('Case A: trait SELECT fragment routes stock to ss.stock when is_stock_separate=1', function (): void {
    $cmd = makeExplainSupplierCostExposed();
    $select = $cmd->exposeSelect();

    // CASE WHEN f.is_stock_separate = 1 THEN ss.stock ELSE fp.stock END
    expect($select)
        ->toContain('CASE WHEN f.is_stock_separate = 1 THEN ss.stock')
        ->toContain('AS stock');

    // Drift guard: ExplainSupplierCostCommand actually uses the trait —
    // without this, a future refactor stripping `use JoinsStockSeparate;`
    // from the command would silently regress the dual-file fix while this
    // test (which uses the trait directly) keeps passing.
    $usedTraits = class_uses(ExplainSupplierCostCommand::class);
    expect($usedTraits)->toHaveKey(JoinsStockSeparate::class);
});

// ─── Case B — SELECT fragment falls through to fp.stock otherwise ────────────

it('Case B: trait SELECT fragment falls through to fp.stock when is_stock_separate=0', function (): void {
    $cmd = makeExplainSupplierCostExposed();
    $select = $cmd->exposeSelect();

    // The ELSE branch of the CASE — for WestCoast and every other
    // is_stock_separate=0 supplier, the JOIN finds no stockseparate row and
    // the CASE picks fp.stock, byte-identical to pre-fix.
    expect($select)->toContain('ELSE fp.stock END');
});

// ─── Case C — SELECT fragment COALESCEs missing stockseparate row to 0 ───────

it('Case C: trait SELECT fragment COALESCEs missing stockseparate row to 0', function (): void {
    $cmd = makeExplainSupplierCostExposed();
    $select = $cmd->exposeSelect();

    // For Ingram catalogue rows where the stockseparate file hasn't yet
    // shipped a particular part, the LEFT JOIN yields NULL on ss.stock.
    // The COALESCE wrapper collapses that NULL to 0 so the rendered table
    // shows '0', not nothing/null.
    expect($select)
        ->toMatch('/^COALESCE\(/')
        ->toContain(', 0)')
        ->toContain('AS stock');
});

// ─── Case D — JOIN gates stockseparate behind f.is_stock_separate=1 ──────────

it('Case D: trait JOIN clause gates the stockseparate JOIN behind f.is_stock_separate=1', function (): void {
    $cmd = makeExplainSupplierCostExposed();
    $join = $cmd->exposeJoin();

    // The stockseparate JOIN's ON clause includes `f.is_stock_separate = 1`,
    // so for WestCoast (is_stock_separate=0) the JOIN matches nothing and
    // ss.stock stays NULL — the CASE falls through to fp.stock. This is what
    // keeps WestCoast byte-identical to pre-fix.
    expect($join)
        ->toContain('LEFT JOIN stockseparate ss')
        ->toContain('ON f.is_stock_separate = 1')
        ->toContain('AND ss.supplier_id = fp.supplierid');

    // Also: the feeds JOIN MUST appear first because the stockseparate JOIN
    // references f.is_stock_separate.
    $feedsPos = strpos($join, 'LEFT JOIN feeds f');
    $ssPos = strpos($join, 'LEFT JOIN stockseparate ss');
    expect($feedsPos)->toBeLessThan($ssPos);
});

// ─── Case E — JOIN normalises SKU match via LOWER(TRIM(...)) ─────────────────

it('Case E: trait JOIN clause normalises SKU match via LOWER(TRIM(...)) for whitespace + case skew', function (): void {
    $cmd = makeExplainSupplierCostExposed();
    $join = $cmd->exposeJoin();

    // Real prod data: feeds_products.suppliersku has trailing whitespace
    // ("CP15851     ") and stockseparate.sku is 'CP15851'. Without the
    // LOWER(TRIM(...)) the JOIN would miss every Ingram row. The DB will
    // honour the comparison — we assert the trait ASKS MySQL to do it.
    expect($join)->toContain('LOWER(TRIM(ss.sku)) = LOWER(TRIM(fp.suppliersku))');
});
