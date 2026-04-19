<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 1 — COMP-06 guardrail
|--------------------------------------------------------------------------
|
| No code under app/Domain/Competitor/ may duplicate VAT math. Every
| gross → ex-VAT conversion goes through Phase 3's PriceCalculator::stripVat.
|
| This test is a content-level grep — false-positives possible in docblocks;
| the mitigation pattern is to describe the anti-pattern in prose rather than
| literal "/ 1.2" substrings (same lesson as Plan 05-01 Deviation #2).
*/

it('contains zero occurrences of "/ 1.2" or "/ 1.20" (VAT-divide short-hand) in app/Domain/Competitor/', function (): void {
    $root = app_path('Domain/Competitor');
    $violations = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        // Catch patterns: `/ 1.2`, `/ 1.20`, `/1.2`, `/1.20`
        if (preg_match('#/\s?1\.20?\b#', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([], 'Found VAT-divide short-hand in: '.implode(', ', $violations));
});

it('imports PriceCalculator from Phase 3 (the only allowed VAT-strip source)', function (): void {
    $root = app_path('Domain/Competitor');
    $importFound = false;
    $stripVatCallFound = false;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (str_contains($contents, 'App\\Domain\\Pricing\\Services\\PriceCalculator')) {
            $importFound = true;
        }
        if (preg_match('/->\s*stripVat\s*\(/', $contents)) {
            $stripVatCallFound = true;
        }
    }

    expect($importFound)->toBeTrue('PriceCalculator is never imported in app/Domain/Competitor/');
    expect($stripVatCallFound)->toBeTrue('No stripVat() invocation under app/Domain/Competitor/');
});

it('defines no local function named stripVat under app/Domain/Competitor/', function (): void {
    $root = app_path('Domain/Competitor');
    $violations = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        // Catch "function stripVat" or "public function stripVat" etc — i.e. DEFINING it
        // (not merely calling $foo->stripVat(...)).
        if (preg_match('/\bfunction\s+stripVat\s*\(/', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([], 'Local stripVat definition leaked into: '.implode(', ', $violations));
});
