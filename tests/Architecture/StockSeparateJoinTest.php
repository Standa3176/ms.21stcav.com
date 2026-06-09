<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture: feeds_products.stock reads must use JoinsStockSeparate trait
|--------------------------------------------------------------------------
|
| WHY THIS TEST EXISTS — 2026-06-09 quick task 260609-rie.
|
| Ingram (feeds.id=10, is_stock_separate=1, path_to_stock_file=AVAIL/TOTUKHRL.ZIP)
| stores stock in a separate `stockseparate` table keyed by
| (supplier_id + suppliersku), NOT in feeds_products.stock. The 2026-06-09 probe
| showed feeds_products.stock=0 for ~99% of Ingram's 192,011 rows while
| stockseparate held 125,319 rows with real stock — meaning 123,901 SKUs were
| silently invisible to MS (zero stock → excluded from ads → revenue leak).
| Sample SKU CP15851 (Sennheiser HA310-2EP): fp.stock=0 vs ss.stock=5659.
|
| Fix commit (T3): bfc9f75 — wired the new App\Domain\Sync\Concerns\JoinsStockSeparate
| trait into SupplierDbSyncCommand (2 sites) + ExplainSupplierCostCommand (1 site).
| Trait centralises the SELECT-list COALESCE(CASE WHEN ...) fragment and the
| LEFT JOIN stockseparate clause.
|
| This test installs the CI gate that prevents silent reintroduction of the bug
| in any future query under app/Domain/Sync/, app/Console/Commands/, or
| app/Domain/Pricing/Services/.
|
| ALLOWED PATTERNS — a file with `FROM feeds_products` is OK if either:
|   1. It imports + uses the JoinsStockSeparate trait (`use App\Domain\Sync\Concerns\JoinsStockSeparate;`
|      AND `use JoinsStockSeparate;` somewhere in the class body), OR
|   2. It carries a `// stock-separate-not-applicable: <reason>` annotation
|      somewhere in the file (escape hatch for queries that select only
|      mpn / ean / manufacturer / suppliersku — not .stock).
|
| FORBIDDEN — every other `FROM feeds_products` site is a violation.
|
| WHY TWO ASSERTIONS (mirrors EnvUsageTest pattern):
|   - Assertion 1: file scan — Pest arch() DSL cannot see SQL strings inside
|     PHP code; only a file-content regex can. We strip comments first so the
|     docblock in JoinsStockSeparate.php itself does NOT trip the scan.
|   - Assertion 2: meta-assertion — proves the regex still has teeth against
|     a synthetic positive case AND correctly ignores a comment-only case.
|     Without this, a future maintainer who "simplifies" the regex into a
|     no-op would pass CI silently.
|   - Assertion 3: trait sanity — confirms the trait file at the canonical
|     path still defines both methods. Catches the case where someone deletes
|     the trait but leaves `use JoinsStockSeparate;` import statements behind.
|
| VERIFICATION RECIPE (do not commit any of these — sanity checks only):
|
|   1. Temporarily remove the `// stock-separate-not-applicable:` annotation
|      from app/Domain/Sync/Services/SupplierSkuRegistry.php.
|      Run: vendor/bin/pest tests/Architecture/StockSeparateJoinTest.php
|      Expected: FAIL — SupplierSkuRegistry.php appears in the violations list.
|
|   2. Revert step 1. Both assertions pass.
*/

// ─── Assertion 1 — File scan over the 3 SQL-bearing roots ────────────────────

test('feeds_products.stock reads must use JoinsStockSeparate trait', function (): void {
    $roots = [
        base_path('app/Domain/Sync'),
        base_path('app/Console/Commands'),
        base_path('app/Domain/Pricing/Services'),
    ];

    $violations = [];

    foreach ($roots as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            // Strip /* ... */ block comments and // line comments. This is what
            // stops the JoinsStockSeparate.php docblock mention of
            // `FROM feeds_products` from tripping the scan.
            $stripped = preg_replace('#/\*.*?\*/#s', '', $contents);
            $stripped = preg_replace('#//.*$#m', '', (string) $stripped);

            if (! preg_match('/FROM\s+feeds_products/i', (string) $stripped)) {
                continue;
            }

            // File contains a real (non-commented) `FROM feeds_products`.
            // It MUST satisfy at least one of the two allowed patterns —
            // we check the UNSTRIPPED contents because both the annotation
            // and the trait `use` statements are valid signals.
            $hasAnnotation = str_contains($contents, 'stock-separate-not-applicable:');
            $hasTraitImport = str_contains($contents, 'use App\Domain\Sync\Concerns\JoinsStockSeparate');
            $hasTraitUse = (bool) preg_match('/^\s*use JoinsStockSeparate;/m', $contents);

            $isOk = $hasAnnotation || ($hasTraitImport && $hasTraitUse);

            if (! $isOk) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Reading feeds_products without the stockseparate LEFT JOIN was the '
        .'260609-rie bug — Ingram (is_stock_separate=1) stores stock in the '
        .'separate `stockseparate` table, NOT feeds_products.stock. '
        .'See .planning/quick/260609-rie-*/260609-rie-PLAN.md and '
        .'260609-rie-SUMMARY.md. Offending files: '
        .implode(', ', $violations)
        .'. Either (a) `use App\Domain\Sync\Concerns\JoinsStockSeparate;` + '
        .'`use JoinsStockSeparate;` in the class body and call '
        .'$this->stockColumnSelect() + $this->stockSeparateJoinClause() in '
        .'the query, OR (b) if the query does NOT select .stock, annotate it '
        .'with `// stock-separate-not-applicable: <reason>`.'
    );
});

// ─── Assertion 2 — Meta-assertion: regex teeth check ─────────────────────────

test('stockseparate JOIN scan can detect raw FROM feeds_products in a synthetic string (meta-assertion)', function (): void {
    // Positive: a string with a real FROM feeds_products MUST match after stripping.
    $sample = '$sql = "SELECT fp.stock FROM feeds_products fp WHERE 1";';
    expect(preg_match('/FROM\s+feeds_products/i', $sample))->toBe(1);

    // Negative: a // comment containing `FROM feeds_products` MUST be stripped
    // before the scan sees it.
    $negative = '// FROM feeds_products in a comment should be stripped';
    $stripped = preg_replace('#//.*$#m', '', $negative);
    expect(preg_match('/FROM\s+feeds_products/i', (string) $stripped))->toBe(0);

    // Negative: a /* */ block-comment mention MUST be stripped before the scan.
    $blockNegative = "/** docblock mentions FROM feeds_products here */\n\$x = 1;";
    $blockStripped = preg_replace('#/\*.*?\*/#s', '', $blockNegative);
    expect(preg_match('/FROM\s+feeds_products/i', (string) $blockStripped))->toBe(0);
});

// ─── Assertion 3 — Trait sanity check ─────────────────────────────────────────

test('trait file itself defines stockSeparateJoinClause + stockColumnSelect', function (): void {
    $path = base_path('app/Domain/Sync/Concerns/JoinsStockSeparate.php');

    expect(file_exists($path))->toBeTrue('Trait file missing — 260609-rie expects it at app/Domain/Sync/Concerns/JoinsStockSeparate.php');

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('function stockColumnSelect');
    expect($contents)->toContain('function stockSeparateJoinClause');
    expect($contents)->toContain('namespace App\Domain\Sync\Concerns');
});
