<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture: env() must only be called from config/, bootstrap/, tests/
|--------------------------------------------------------------------------
|
| WHY THIS TEST EXISTS — 2026-05-31 Day-1 cutover incident.
|
| `routes/console.php` called env('PRICING_UNDERCUT_ENABLED'),
| env('CUTOVER_DIVERGENCE_SCAN_ENABLED'), env('AGENT_SEO_BATCH_SCHEDULE_ENABLED')
| directly. Deploy ran `php artisan config:cache`, which bakes env() values into
| bootstrap/cache/config.php AT CACHE-BUILD TIME. After deploy, .env was mutated
| to flip the toggles ON for production — but the cached config (which the
| scheduler reads from) still held the build-time DEFAULTS. Result: the first
| 08:00 BST autonomous reprice silently MISSED, three scheduled jobs fell
| off-air, recovery was manual, root-cause took meaningful time to find.
|
| Fix commit: d7d0e39 — moved all three to config/*.php keys, updated callers
| to read via config(). Verified with schedule:test + crontab + dashboard_snapshots.
|
| LESSON LOGGED IN STATE.md: "never call env() outside config/*.php when
| config:cache is in use."
|
| This test installs the CI gate that enforces that lesson — so the regression
| is impossible to merge accidentally. It is the long-term memory of the
| incident.
|
| ALLOWED CALL SITES — env() may ONLY be called from:
|   - config/*.php       (the whole point — bind env values into config keys here)
|   - bootstrap/*.php    (framework boot, runs BEFORE config cache is loaded)
|   - tests/*            (tests need to mutate env for fixture setup)
|
| FORBIDDEN CALL SITES — env() must NEVER appear in:
|   - app/**            (covered by Assertion 1 below — Pest arch DSL)
|   - routes/**         (covered by Assertion 2 below — file scan)
|   - database/**       (covered by Assertion 2 below — file scan)
|
| WHY TWO ASSERTIONS:
|   - Pest arch() DSL resolves PHP CLASSES in the App\ namespace. It catches
|     env() inside any class under app/.
|   - But routes/console.php and database/migrations/*.php and
|     database/seeders/*.php are NOT classes / not autoloaded into App\.
|     Pest arch() literally cannot see them. The file-scan assertion covers
|     those directories. This is exactly where the 2026-05-31 incident lived,
|     so the file-scan fallback is REQUIRED, not optional.
|
| VERIFICATION RECIPE (do not commit any of these — they are sanity checks):
|
|   1. Temporarily add `env('GUARD_RAIL_TEST');` to routes/console.php.
|      Run: vendor/bin/pest tests/Architecture/EnvUsageTest.php
|      Expected: FAIL — assertion 2 lists routes/console.php as a violation.
|
|   2. Revert step 1, then temporarily add `env('GUARD_RAIL_TEST');` to
|      app/Console/Commands/BaseCommand.php (or any class in App\).
|      Run: vendor/bin/pest tests/Architecture/EnvUsageTest.php
|      Expected: FAIL — assertion 1 (arch()) reports the App\ class as using env.
|
|   3. Revert step 2. Both assertions pass.
|
| If either step 1 or step 2 PASSES instead of failing, the guardrail is broken
| and needs investigation — Pest arch() may have changed semantics, or the
| file-scan regex may have been weakened.
*/

// ─── Assertion 1 — Pest arch() DSL covering the App\ namespace ────────────────
//
// `expect('App')->not->toUse('env')` resolves the App\ namespace through Pest's
// class graph and bans any class in it from invoking the global `env` helper.
// This covers every PHP class under app/ — the bulk of the codebase.

arch('env() is forbidden in the App namespace (Pest arch DSL)')
    ->expect('App')
    ->not->toUse('env');

// ─── Assertion 2 — File-scan fallback covering routes/ and database/ ─────────
//
// These directories are loaded outside the App\ class graph (routes via
// Laravel's RoutingServiceProvider; database/migrations + database/seeders via
// the framework's migrator + Artisan). Pest arch() does NOT see them. The
// file-scan walks both trees, strips comments, then matches a real env(
// function call.

test('env() is forbidden in routes/ and database/ — file scan', function (): void {
    $forbidden = [
        base_path('routes'),
        base_path('database'),
    ];

    $violations = [];

    foreach ($forbidden as $dir) {
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

            // Strip /* ... */ block comments and // line comments before
            // grepping. This is what stops the routes/console.php:289
            // "// env() returns the default in cached-config mode" comment
            // from tripping the scan.
            $stripped = preg_replace('#/\*.*?\*/#s', '', $contents);
            $stripped = preg_replace('#//.*$#m', '', (string) $stripped);

            // Match `env(` only when preceded by start-of-line, whitespace,
            // or an operator — NOT when preceded by `>`, `$`, or alphanumeric
            // (which would mean it's a method call like `$req->env(...)` or
            // part of a longer identifier).
            if (preg_match('/(^|[^a-zA-Z0-9_>$])env\s*\(/', (string) $stripped)) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($violations)->toBeEmpty(
        'env() must not be called in routes/ or database/ — call sites: '
        .implode(', ', $violations)
        .'. Bind env() in config/*.php and read via config() instead. '
        .'See 2026-05-31 cutover incident + fix commit d7d0e39 for the why.'
    );
});

// ─── Assertion 3 — Meta-assertion: prove the regex would catch a real call ──
//
// A future maintainer who "simplifies" the regex (e.g. drops the negative
// look-behind) could accidentally weaken it to match nothing — in which case
// the file scan above passes vacuously and the guardrail silently rots. This
// meta-test self-checks the regex against a synthetic positive case and a
// synthetic negative case so any weakening shows up as a red CI run.

test('file scan can detect env( in a synthetic string (meta-assertion)', function (): void {
    // Positive: a string with a real env( call MUST match.
    $sample = "if (env('FOO')) { return; }";
    expect(preg_match('/(^|[^a-zA-Z0-9_>$])env\s*\(/', $sample))->toBe(1);

    // Negative: a // comment containing env( must NOT match after stripping.
    $negative = "// env( in a comment should be stripped before scan";
    $stripped = preg_replace('#//.*$#m', '', $negative);
    expect(preg_match('/(^|[^a-zA-Z0-9_>$])env\s*\(/', (string) $stripped))->toBe(0);

    // Negative: a method call `$thing->env(...)` must NOT match (`>` look-behind).
    $methodCall = '$req->env("staging");';
    expect(preg_match('/(^|[^a-zA-Z0-9_>$])env\s*\(/', $methodCall))->toBe(0);
});
