<?php

declare(strict_types=1);

/**
 * Phase 2 Plan 02-04 — policy-template integrity guardrail (Pitfall P2-H).
 *
 * Any `{{ Placeholder }}` literal leaking into a shipped Policy indicates
 * `shield:generate` regenerated a hand-written policy and the post-generate
 * restore protocol was missed. Catches regressions at CI time.
 *
 * Plan 02-05 will add an architecture-suite version of this test with broader
 * coverage (stub detection, role-assignment drift checks). This Feature-level
 * version exists now so Plan 02-04's shield:generate step is defended
 * post-commit even before the formal guardrail lands.
 */
it('has no Shield {{ Placeholder }} literals in hand-written Policies', function (): void {
    $paths = [
        app_path('Policies'),
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/Products/Policies'),
        app_path('Domain/Suggestions/Policies'),
        app_path('Domain/Sync/Policies'),
    ];

    $leaks = [];
    foreach ($paths as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (str_contains($source, '{{ ')) {
                $leaks[] = $file->getPathname();
            }
        }
    }

    expect($leaks)->toBe([], 'Shield placeholder literal leaked into: ' . implode(', ', $leaks));
});
