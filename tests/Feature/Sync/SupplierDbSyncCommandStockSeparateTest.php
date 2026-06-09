<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Concerns\JoinsStockSeparate;
use App\Domain\Sync\Services\SupplierFreshnessResolver;

/**
 * 260609-rie — stockseparate JOIN coverage for SupplierDbSyncCommand.
 *
 * Boundary strategy (matches the helper-method-only coverage in the existing
 * SupplierDbSyncCommandTest.php lines 10–22): the new trait emits SQL strings,
 * and the downstream consumer of the trait's output is buildBestOfferMap,
 * which receives row arrays. We test the SQL strings directly (no live mysqli)
 * and we feed buildBestOfferMap synthetic rows that simulate what the LEFT
 * JOIN would have produced. The DB's LEFT-JOIN semantics are MySQL 8.0's job;
 * we trust them.
 *
 * Cases A–E mirror the scope-decision invariants from the PLAN:
 *   A — Ingram (is_stock_separate=1) with stockseparate row → ss.stock wins.
 *   B — WestCoast (is_stock_separate=0) → fp.stock wins (byte-identical).
 *   C — Ingram catalog row with NO stockseparate row → COALESCE → 0.
 *   D — Mixed batch (Ingram + WestCoast) → each resolves correctly.
 *   E — Case-insensitive + trimmed SKU match (trait emits LOWER(TRIM(...))).
 */
function makeStockSeparateSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
    );
}

/**
 * Anonymous helper that uses the JoinsStockSeparate trait directly to expose
 * the protected SELECT/JOIN fragments — SupplierDbSyncCommand is `final` so
 * we can't subclass it. Asserting the SAME trait the production command
 * uses guarantees byte-identical fragments (Pest's autoloader resolves to
 * the canonical app/Domain/Sync/Concerns/JoinsStockSeparate.php).
 *
 * @return object{exposeSelect: callable, exposeJoin: callable}
 */
function makeStockSeparateTraitExposer(): object
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

// ─── Case A — Ingram (is_stock_separate=1) with stockseparate row wins ───────

it('Case A: Ingram is_stock_separate=1 row resolves stock from stockseparate (5659) not fp.stock (0)', function (): void {
    $cmd = makeStockSeparateSyncCommand();
    $exposed = makeStockSeparateTraitExposer();

    // Sanity-1: trait emits the canonical SELECT fragment.
    expect($exposed->exposeSelect())
        ->toContain('COALESCE')
        ->toContain('is_stock_separate = 1')
        ->toContain('AS stock');

    // Sanity-2: SupplierDbSyncCommand actually uses the trait (drift guard —
    // if a future refactor strips `use JoinsStockSeparate;` the trait fragment
    // would be inert and stock-separate suppliers would silently regress).
    $usedTraits = class_uses(SupplierDbSyncCommand::class);
    expect($usedTraits)->toHaveKey(JoinsStockSeparate::class);

    // Simulate the row the LEFT JOIN would emit for Ingram CP15851:
    // - fp.stock=0 (the bug source — Ingram only stores 0 here)
    // - ss.stock=5659 (the real value from stockseparate)
    // - COALESCE(CASE WHEN f.is_stock_separate=1 THEN ss.stock ELSE fp.stock END, 0) → 5659
    // - DB emits this under alias `stock` so $row['stock'] = '5659'.
    $rows = [
        [
            'mpn' => 'HA310-2EP',
            'suppliersku' => 'CP15851',
            'supplierid' => '10',
            'supplier_name' => 'Ingram',
            'price' => '120.50',
            'stock' => '5659',
        ],
    ];

    $map = $cmd->buildBestOfferMap($rows);

    expect($map['ha310-2ep']['stock'])->toBe(5659)
        ->and($map['ha310-2ep']['in_stock'])->toBeTrue()
        ->and($map['ha310-2ep']['supplier'])->toBe('Ingram')
        ->and($map['cp15851']['stock'])->toBe(5659)
        ->and($map['cp15851']['in_stock'])->toBeTrue();
});

// ─── Case B — WestCoast (is_stock_separate=0) byte-identical to pre-fix ──────

it('Case B: WestCoast is_stock_separate=0 row resolves stock from fp.stock (42) — JOIN inert', function (): void {
    $cmd = makeStockSeparateSyncCommand();

    // Simulate WestCoast row: is_stock_separate=0 so the stockseparate JOIN
    // gate `f.is_stock_separate = 1` never matches. CASE falls through to
    // fp.stock. DB emits `stock`=42, output is byte-identical to pre-fix.
    $rows = [
        [
            'mpn' => 'WC-PART-Y',
            'suppliersku' => 'WC1234',
            'supplierid' => '39',
            'supplier_name' => 'WestCoast',
            'price' => '0.42',
            'stock' => '42',
        ],
    ];

    $map = $cmd->buildBestOfferMap($rows);

    expect($map['wc-part-y']['stock'])->toBe(42)
        ->and($map['wc-part-y']['in_stock'])->toBeTrue()
        ->and($map['wc-part-y']['supplier'])->toBe('WestCoast');
});

// ─── Case C — Ingram with NO matching stockseparate row → COALESCE → 0 ───────

it('Case C: Ingram is_stock_separate=1 with NO matching stockseparate row → COALESCE→0, in_stock=false', function (): void {
    $cmd = makeStockSeparateSyncCommand();
    $exposed = makeStockSeparateTraitExposer();

    // Sanity: trait SQL contains the COALESCE that protects the NULL→0 fallback.
    expect($exposed->exposeSelect())->toContain('COALESCE');

    // Simulate Ingram catalog row where stockseparate has no matching row.
    // The trait's COALESCE(CASE WHEN ... ELSE ... END, 0) makes that NULL
    // become 0, NOT NULL. DB emits `stock`=0 → in_stock=false.
    $rows = [
        [
            'mpn' => 'NEW-INGRAM-PART',
            'suppliersku' => 'NEWING-001',
            'supplierid' => '10',
            'supplier_name' => 'Ingram',
            'price' => '15.00',
            'stock' => '0',
        ],
    ];

    $map = $cmd->buildBestOfferMap($rows);

    expect($map['new-ingram-part']['stock'])->toBe(0)
        ->and($map['new-ingram-part']['in_stock'])->toBeFalse()
        // No in-stock offer → "any" branch picks the only available price.
        ->and($map['new-ingram-part']['buy'])->toBe('15.00');
});

// ─── Case D — Mixed batch: Ingram + WestCoast each resolves correctly ────────

it('Case D: mixed batch — Ingram (ss.stock=5659) + WestCoast (fp.stock=42) both resolve via buildBestOfferMap', function (): void {
    $cmd = makeStockSeparateSyncCommand();

    // Two distinct MPNs across two distinct suppliers. After the JOIN, each
    // row carries the resolved `stock` value the trait emits.
    $rows = [
        [
            'mpn' => 'HA310-2EP',
            'suppliersku' => 'CP15851',
            'supplierid' => '10',
            'supplier_name' => 'Ingram',
            'price' => '120.50',
            'stock' => '5659',
        ],
        [
            'mpn' => 'WC-PART-Y',
            'suppliersku' => 'WC1234',
            'supplierid' => '39',
            'supplier_name' => 'WestCoast',
            'price' => '0.42',
            'stock' => '42',
        ],
    ];

    $map = $cmd->buildBestOfferMap($rows);

    expect($map['ha310-2ep']['stock'])->toBe(5659)
        ->and($map['ha310-2ep']['supplier'])->toBe('Ingram')
        ->and($map['ha310-2ep']['in_stock'])->toBeTrue()
        ->and($map['wc-part-y']['stock'])->toBe(42)
        ->and($map['wc-part-y']['supplier'])->toBe('WestCoast')
        ->and($map['wc-part-y']['in_stock'])->toBeTrue();
});

// ─── Case E — Trait emits case-insensitive + trimmed SKU JOIN condition ──────

it('Case E: trait emits LOWER(TRIM(ss.sku)) = LOWER(TRIM(fp.suppliersku)) — handles whitespace + case skew', function (): void {
    $exposed = makeStockSeparateTraitExposer();

    $join = $exposed->exposeJoin();

    // The DB will honour the LOWER(TRIM(...)) match — we assert the trait
    // ASKS MySQL to do it. Real prod data: feeds_products.suppliersku has
    // trailing whitespace ("CP15851     ") and stockseparate.sku is 'CP15851';
    // without LOWER(TRIM(...)), the JOIN would miss.
    expect($join)
        ->toContain('LEFT JOIN feeds f')
        ->toContain('LEFT JOIN stockseparate ss')
        ->toContain('f.is_stock_separate = 1')
        ->toContain('ss.supplier_id = fp.supplierid')
        ->toContain('LOWER(TRIM(ss.sku)) = LOWER(TRIM(fp.suppliersku))');
});
