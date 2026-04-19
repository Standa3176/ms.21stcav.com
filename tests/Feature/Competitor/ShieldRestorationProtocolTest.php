<?php

declare(strict_types=1);

/**
 * Phase 5 Plan 04a — guardrail for the P5-F shield:generate restoration protocol.
 *
 * Every time a plan runs `php artisan shield:generate --all`, Shield 3.9.10
 * overwrites every discoverable hand-written Policy with a permission-based
 * stub AND may emit `{{ Placeholder }}` literal substrings in the fresh
 * stubs (observed in Phase 1 Plan 02 + Phase 4 Plan 04).
 *
 * This test pairs with tests/Architecture/PolicyTemplateIntegrityTest.php
 * (which does the same grep but in Architecture scope) and ensures the
 * feature suite also catches a missed restoration before CI green-lights
 * a broken RBAC release.
 */
it('no Policy file contains a Shield {{ Placeholder }} literal (feature-level guardrail)', function (): void {
    $paths = [
        app_path('Policies'),
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/Competitor/Policies'),
        app_path('Domain/CRM/Policies'),
        app_path('Domain/Pricing/Policies'),
        app_path('Domain/Products/Policies'),
        app_path('Domain/Suggestions/Policies'),
        app_path('Domain/Sync/Policies'),
    ];

    $leaks = [];
    foreach ($paths as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (str_contains($source, '{{ ')) {
                $leaks[] = $file->getPathname();
            }
        }
    }

    expect($leaks)->toBe([], 'Shield placeholder literal leaked into: '.implode(', ', $leaks).
        ' — re-run the P5-F restoration protocol: git checkout HEAD -- <path> for each leaked file.');
});

it('no Shield-generated IntegrationEventPolicy stub exists at app/Foundation/Integration/Policies/', function (): void {
    // Phase 4 Plan 04 — shield:generate created app/Foundation/Integration/Policies/IntegrationEventPolicy.php
    // as a permission-based stub that conflicted with our hand-written CrmPushLogPolicy via Laravel's
    // auto-discovery. We deleted it after restoration; this test prevents its return.
    $stubPath = app_path('Foundation/Integration/Policies');

    expect(is_dir($stubPath))->toBeFalse(
        'app/Foundation/Integration/Policies/ exists — Shield 3.9.10 auto-creates IntegrationEventPolicy.php '
        .'here which conflicts with CrmPushLogPolicy (Phase 4 Plan 04 decision). Run `rm -rf `'.$stubPath.'` '
        .'to remove; re-assert CrmPushLogPolicy via Gate::policy binding in AppServiceProvider.'
    );
});
