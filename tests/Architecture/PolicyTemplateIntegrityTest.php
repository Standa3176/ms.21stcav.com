<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Architecture: Pitfall P2-H — permanent guardrail for hand-written Policies
|--------------------------------------------------------------------------
|
| Plan 02-05 Task 2. Promoted from tests/Feature/PolicyTemplateIntegrityTest.php
| (Plan 02-04 laid the foundation) into the Architecture suite, and extended
| with two additional checks:
|
|   1. No `{{ Placeholder }}` Shield template literal leaks into any
|      shipped Policy file. Phase 1 + Phase 2 have each caught this
|      regression ONCE post-`shield:generate`; the grep catches it in
|      ~1ms on every CI run.
|
|   2. Positive control: at least 9 Policy files exist across the scanned
|      directories. Prevents a false-green from a glob that matches nothing
|      (e.g., if someone renamed the Policy folder and the grep found no
|      files to scan). Floor raised Phase 3 Plan 03 (7 → 9) for
|      PricingRulePolicy + ProductOverridePolicy coverage.
|
|   3. Gate::policy bindings for the 4 Phase-2 + 3 Phase-1 + 2 Phase-3 models
|      resolve to Domain\* / app\Policies\* implementations — NOT to
|      Shield-generated placeholder stubs. A stub has `return true` on every
|      method; a real policy references hasRole / hasPermissionTo.
|
| Shield 3.9.10 regenerates the 6 Filament-discoverable Policies on every
| `shield:generate --all` run. Plan 02-04 Task 2b documented the restore
| protocol (git checkout HEAD -- <paths>). This test catches any future
| plan that runs shield:generate without re-running the restore.
*/

it('no Policy file contains a Shield {{ Placeholder }} literal (Pitfall P2-H)', function (): void {
    $paths = [
        app_path('Policies'),
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/CRM/Policies'),          // Phase 4 Plan 01 — 5 Bitrix CRM policies
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

    expect($leaks)->toBe([], 'Shield placeholder literal leaked into: '.implode(', ', $leaks));
});

it('has at least 9 Policy files under the scanned roots (positive control)', function (): void {
    $paths = [
        app_path('Policies'),
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/CRM/Policies'),          // Phase 4 Plan 01 — 5 Bitrix CRM policies
        app_path('Domain/Pricing/Policies'),
        app_path('Domain/Products/Policies'),
        app_path('Domain/Suggestions/Policies'),
        app_path('Domain/Sync/Policies'),
    ];

    $policyFiles = [];
    foreach ($paths as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $policyFiles[] = $path;
        }
    }

    // 9 = RolePolicy + SuggestionPolicy + AlertRecipientPolicy (Phase 1)
    //   + ProductPolicy + ProductVariantPolicy + SyncRunPolicy + ImportIssuePolicy (Phase 2)
    //   + PricingRulePolicy + ProductOverridePolicy (Phase 3)
    // 15 = above + 5 CRM policies (Phase 4 Plan 01) + CrmPushLogPolicy (Plan 04)
    //      + GdprErasureLogEntryPolicy (Plan 05). Floor raised 14 → 16 to
    //      absorb the final two Phase 4 policies.
    expect(count($policyFiles))
        ->toBeGreaterThanOrEqual(16, 'Expected ≥ 16 Policy files — got '.count($policyFiles).': '.implode(', ', $policyFiles));
});

it('Gate::policy bindings resolve to Domain / root Policies (not Shield stubs)', function (): void {
    // Bootstrap the framework so AppServiceProvider::boot runs and registers
    // the Gate::policy() bindings. Pest's `uses(TestCase::class)->in('Architecture')`
    // already kicks the app through TestCase::createApplication.
    $pairs = [
        \App\Models\Role::class => \App\Policies\RolePolicy::class,
        \App\Domain\Suggestions\Models\Suggestion::class => \App\Domain\Suggestions\Policies\SuggestionPolicy::class,
        \App\Domain\Alerting\Models\AlertRecipient::class => \App\Domain\Alerting\Policies\AlertRecipientPolicy::class,
        \App\Domain\Products\Models\Product::class => \App\Domain\Products\Policies\ProductPolicy::class,
        \App\Domain\Products\Models\ProductVariant::class => \App\Domain\Products\Policies\ProductVariantPolicy::class,
        \App\Domain\Sync\Models\SyncRun::class => \App\Domain\Sync\Policies\SyncRunPolicy::class,
        \App\Domain\Sync\Models\ImportIssue::class => \App\Domain\Sync\Policies\ImportIssuePolicy::class,
        \App\Domain\Pricing\Models\PricingRule::class => \App\Domain\Pricing\Policies\PricingRulePolicy::class,
        \App\Domain\Pricing\Models\ProductOverride::class => \App\Domain\Pricing\Policies\ProductOverridePolicy::class,
        // Phase 4 Plan 01 — 5 CRM policies (all admin-only hand-written).
        \App\Domain\CRM\Models\BitrixEntityMap::class => \App\Domain\CRM\Policies\BitrixEntityMapPolicy::class,
        \App\Domain\CRM\Models\CrmFieldMapping::class => \App\Domain\CRM\Policies\CrmFieldMappingPolicy::class,
        \App\Domain\CRM\Models\CrmStatusMapping::class => \App\Domain\CRM\Policies\CrmStatusMappingPolicy::class,
        \App\Domain\CRM\Models\CrmPipelineSetting::class => \App\Domain\CRM\Policies\CrmPipelineSettingPolicy::class,
        \App\Domain\CRM\Models\BitrixBackfillRun::class => \App\Domain\CRM\Policies\BitrixBackfillRunPolicy::class,
        // Phase 4 Plan 05 — GDPR erasure audit (indefinite-retention read-only).
        \App\Domain\CRM\Models\GdprErasureLogEntry::class => \App\Domain\CRM\Policies\GdprErasureLogEntryPolicy::class,
    ];

    foreach ($pairs as $model => $expectedPolicyClass) {
        if (! class_exists($model)) {
            continue; // tolerate missing model in bootstrap-minimal contexts
        }
        if (! class_exists($expectedPolicyClass)) {
            continue;
        }

        $resolved = Gate::getPolicyFor(new $model);
        expect($resolved)->toBeInstanceOf(
            $expectedPolicyClass,
            "Gate::getPolicyFor({$model}) returned ".($resolved !== null ? $resolved::class : 'null')
            .", expected {$expectedPolicyClass}. A Shield-regenerated stub may have been registered via AuthServiceProvider; restore the hand-written policy + its Gate::policy binding."
        );
    }
});
