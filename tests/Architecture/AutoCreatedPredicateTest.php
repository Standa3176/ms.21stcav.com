<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture: vacuous auto_create_status predicates forbidden in app/
|--------------------------------------------------------------------------
|
| WHY THIS TEST EXISTS — 2026-06-06 dry-run silent-bloat incident.
|
| `RetryMissingImagesCommand` filtered "auto-created products only" with
| `whereNotNull('auto_create_status')`. But the `auto_create_status` column
| was added by migration `2026_04_22_100300_add_auto_create_columns_to_
| products_table.php` as `NOT NULL DEFAULT 'manual'`, with an explicit
| belt-and-braces backfill in the same migration's up() body:
|
|     DB::table('products')
|         ->whereNull('auto_create_status')
|         ->update(['auto_create_status' => 'manual']);
|
| Consequence: every row has a value, so `whereNotNull` is a NO-OP. The
| 2026-06-06 Manhattan retry dry-run silently surfaced ~5,668 candidates
| instead of the expected ~36 because thousands of legacy WC-migrated
| 'manual' rows were never excluded.
|
| Uncovered by quick task 260606-mx9; canonical fix shipped in 260606-o63
| as Product::scopeAutoCreated (returns `where('auto_create_status', '!=',
| 'manual')`). Callers MUST invoke `Product::query()->autoCreated()`.
|
| This test installs the CI gate so the vacuous predicate cannot be merged
| back into app/. Same long-term-memory pattern as EnvUsageTest (2026-05-31
| cutover env() incident).
|
| FORBIDDEN PATTERNS — anywhere under app/:
|   1. whereNotNull('auto_create_status')   — single quotes
|   2. whereNotNull("auto_create_status")   — double quotes
|   3. whereNull('auto_create_status')      — single quotes (ALSO vacuous —
|                                              the inverse, "legacy only",
|                                              must use where = 'manual')
|   4. whereNull("auto_create_status")      — double quotes
|   5. auto_create_status IS NOT NULL       — raw SQL fragment (regex)
|   6. auto_create_status IS NULL           — raw SQL fragment (regex)
|
| CANONICAL REPLACEMENTS:
|   - Want "auto-created products only" → `->autoCreated()` (Product scope)
|   - Want "legacy WC products only"   → `->where('auto_create_status',
|                                          'manual')`
|
| SCAN SCOPE — base_path('app') ONLY. The following are legitimately
| allowed and NOT scanned because the iterator never visits them:
|   - tests/**         (this file + ProductScopeAutoCreatedTest mention
|                       the patterns as string literals)
|   - database/migrations/**   (the original migration legitimately writes
|                                `whereNull('auto_create_status')` in the
|                                backfill — that ran ONCE at install time
|                                and is the reason this guardrail exists)
|   - database/factories/**    (factories may set the column to specific
|                                values)
|
| Because the scan walks ONLY base_path('app'), no exclude-list is needed
| — the directories above are simply not visited.
|
| WHY A FILE SCAN (not Pest's arch() DSL): arch()'s ->expect()->not->toUse()
| resolves PHP class graph references — it does NOT see raw string literals
| like 'auto_create_status' or method calls like `whereNotNull`. A file scan
| is required.
|
| VERIFICATION RECIPE (do not commit any of these — they are sanity checks):
|
|   1. Temporarily inject `Product::query()->whereNotNull('auto_create_status')->get();`
|      into app/Console/Commands/RetryMissingImagesCommand.php perform() body.
|      Run: vendor/bin/pest tests/Architecture/AutoCreatedPredicateTest.php
|      Expected: FAIL — assertion 1 lists
|        app/Console/Commands/RetryMissingImagesCommand.php:<line> as a violation.
|
|   2. Revert step 1. Then temporarily inject
|      `Product::query()->whereNull("auto_create_status")->get();`
|      anywhere under app/ (e.g. AutoCreateHealthPage perform helper).
|      Run: vendor/bin/pest tests/Architecture/AutoCreatedPredicateTest.php
|      Expected: FAIL — assertion 1 lists the file:line.
|
|   3. Revert step 2. Then temporarily inject a raw query containing
|      `DB::select("SELECT * FROM products WHERE auto_create_status IS NOT NULL");`
|      under app/.
|      Run: vendor/bin/pest tests/Architecture/AutoCreatedPredicateTest.php
|      Expected: FAIL — assertion 1 lists the file:line.
|
|   4. Revert step 3. All assertions pass.
|
| If ANY of steps 1-3 PASSES instead of failing, the guardrail is broken
| (regex weakened, comment-strip too aggressive, or scope too narrow) and
| needs investigation.
*/

// ─── Assertion 1 — File scan over base_path('app') ─────────────────────────
//
// One alternation-regex covers all 6 forbidden patterns; per-match the
// scanner records the file path and line number so the failure message
// points the reviewer directly at the offender.

test('vacuous auto_create_status predicates are forbidden in app/ — file scan', function (): void {
    $dir = base_path('app');

    expect(is_dir($dir))->toBeTrue();

    $violations = [];

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

        // Strip /* ... */ block comments and // line comments before
        // grepping — mirrors EnvUsageTest's strip pipeline. This is what
        // stops Product.php's docblock-referenced "whereNotNull(
        // 'auto_create_status')" mention from tripping the scan.
        $stripped = preg_replace('#/\*.*?\*/#s', '', $contents);
        $stripped = preg_replace('#//.*$#m', '', (string) $stripped);

        $pattern = '/(whereNotNull\s*\(\s*[\'"]auto_create_status[\'"]\s*\))'
            .'|(whereNull\s*\(\s*[\'"]auto_create_status[\'"]\s*\))'
            .'|(auto_create_status\s+IS\s+(NOT\s+)?NULL)/i';

        if (preg_match_all($pattern, (string) $stripped, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $offset = $match[1];
                $line = substr_count(substr((string) $stripped, 0, $offset), "\n") + 1;
                $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $violations[] = $rel.':'.$line;
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Vacuous auto_create_status predicate(s) found — use '
        .'Product::query()->autoCreated() instead. The column is NOT NULL '
        ."DEFAULT 'manual' per migration 2026_04_22_100300, so whereNotNull/"
        .'whereNull are no-ops. See quick task 260606-o63 (this fix) and '
        .'260606-mx9 (bug uncovering). Call sites: '
        .implode(', ', $violations)
    );
});

// ─── Assertion 2 — Meta-assertion: regex catches synthetic positives and ───
//                  ignores synthetic negatives + comment-stripped sites.
//
// A future maintainer who "simplifies" the regex (e.g. drops a branch of
// the alternation) could weaken it to match nothing — in which case the
// file scan passes vacuously and the guardrail silently rots. This meta-
// test self-checks each of the 6 forbidden patterns matches its synthetic
// positive case AND that the comment-strip pipeline filters comment-only
// sites + that innocent calls like `->where('auto_create_status', 'draft')`
// or the canonical `->where('auto_create_status', '!=', 'manual')` (the
// scope's IMPLEMENTATION) do NOT match — only the IS-NOT-NULL / IS-NULL
// shape is forbidden.

test('forbidden-pattern regexes detect synthetic positives + ignore negatives (meta-assertion)', function (): void {
    $pattern = '/(whereNotNull\s*\(\s*[\'"]auto_create_status[\'"]\s*\))'
        .'|(whereNull\s*\(\s*[\'"]auto_create_status[\'"]\s*\))'
        .'|(auto_create_status\s+IS\s+(NOT\s+)?NULL)/i';

    // ─── Positive cases (each forbidden form MUST match) ───────────────────
    $positives = [
        "\$q->whereNotNull('auto_create_status');",                       // single quotes
        '$q->whereNotNull("auto_create_status");',                        // double quotes
        "\$q->whereNull('auto_create_status');",                          // single quotes
        '$q->whereNull("auto_create_status");',                           // double quotes
        'WHERE auto_create_status IS NOT NULL ORDER BY id',               // raw SQL upper
        'where auto_create_status is null and id > 0',                    // raw SQL lower (case-insensitive flag)
    ];

    foreach ($positives as $i => $sample) {
        expect(preg_match($pattern, $sample))->toBe(
            1,
            "Forbidden-pattern regex FAILED to match synthetic positive case #{$i}: {$sample}"
        );
    }

    // ─── Negative cases that MUST NOT match raw ────────────────────────────
    $negativesRaw = [
        // Canonical scope implementation — must NOT trip the guardrail.
        "return \$query->where('auto_create_status', '!=', 'manual');",
        // Specific-status reads (R-status disposition) — must NOT trip.
        "\$q->where('auto_create_status', 'draft');",
        "\$q->whereIn('auto_create_status', ['draft', 'pending_review']);",
        // The new canonical scope call — must NOT trip.
        "\$q->autoCreated();",
        // A passing field reference / column alias — must NOT trip.
        "selectRaw('auto_create_status, COUNT(*) as n')",
    ];

    foreach ($negativesRaw as $i => $sample) {
        expect(preg_match($pattern, $sample))->toBe(
            0,
            "Forbidden-pattern regex FALSELY matched innocent negative case #{$i}: {$sample}"
        );
    }

    // ─── Comment-stripped pipeline negatives ───────────────────────────────
    //
    // Sites where the forbidden text appears INSIDE a comment must NOT
    // trip the scan AFTER the strip pipeline runs (mirrors the production
    // strip applied in assertion 1).
    $commented = [
        "// \$q->whereNotNull('auto_create_status'); — legacy, removed",
        "/* historical: auto_create_status IS NOT NULL was vacuous */",
        "/**\n * Docblock referencing whereNotNull('auto_create_status').\n */",
    ];

    foreach ($commented as $i => $sample) {
        $stripped = preg_replace('#/\*.*?\*/#s', '', $sample);
        $stripped = preg_replace('#//.*$#m', '', (string) $stripped);
        expect(preg_match($pattern, (string) $stripped))->toBe(
            0,
            "After comment-strip, sample #{$i} should NOT match but did: {$sample}"
        );
    }
});
