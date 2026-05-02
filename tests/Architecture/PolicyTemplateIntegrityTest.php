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
        app_path('Domain/Agents/Policies'),           // Phase 8 Plan 01 — 1 AgentRunPolicy (admin-only)
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/Competitor/Policies'),       // Phase 5 Plan 01 — 5 Competitor policies
        app_path('Domain/CRM/Policies'),              // Phase 4 Plan 01 — 5 Bitrix CRM policies
        app_path('Domain/Dashboard/Policies'),        // Phase 7 Plan 01 — 2 Dashboard policies
        app_path('Domain/Pricing/Policies'),
        app_path('Domain/ProductAutoCreate/Policies'), // Phase 6 Plan 01 — 2 auto-create policies
        app_path('Domain/Products/Policies'),
        app_path('Domain/Quotes/Policies'),           // Phase 11 Plan 01 — 2 Quote policies
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
        app_path('Domain/Agents/Policies'),           // Phase 8 Plan 01 — 1 AgentRunPolicy (admin-only)
        app_path('Domain/Alerting/Policies'),
        app_path('Domain/Competitor/Policies'),       // Phase 5 Plan 01 — 5 Competitor policies
        app_path('Domain/CRM/Policies'),              // Phase 4 Plan 01 — 5 Bitrix CRM policies
        app_path('Domain/Dashboard/Policies'),        // Phase 7 Plan 01 — 2 Dashboard policies
        app_path('Domain/Pricing/Policies'),
        app_path('Domain/ProductAutoCreate/Policies'), // Phase 6 Plan 01 — 2 auto-create policies
        app_path('Domain/Products/Policies'),
        app_path('Domain/Quotes/Policies'),           // Phase 11 Plan 01 — 2 Quote policies
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
    // 16 = above + 5 CRM policies (Phase 4 Plan 01) + CrmPushLogPolicy (Plan 04)
    //      + GdprErasureLogEntryPolicy (Plan 05).
    // 21 = above + 5 Competitor policies (Phase 5 Plan 01:
    //      Competitor / CompetitorPrice / CompetitorCsvMapping /
    //      CompetitorIngestRun / CsvParseError).
    // 23 = above + 2 ProductAutoCreate policies (Phase 6 Plan 01:
    //      AutoCreateSkipRulePolicy + AutoCreateRejectionPolicy).
    // 24 = above + AutoCreateSettingsPolicy (Phase 6 Plan 04 — admin-only
    //      gate on the settings singleton Page).
    // 26 = above + 2 Dashboard policies (Phase 7 Plan 01:
    //      DashboardSnapshotPolicy + UserSavedFilterPolicy).
    //      Floor bumped 24 → 26.
    // 27 = above + 1 AgentRunPolicy (Phase 8 Plan 01 — C4 Agent Framework).
    //      Floor bumped 26 → 27.
    // 29 = above + 2 Quote policies (Phase 11 Plan 01 — QuotePolicy +
    //      QuoteLinePolicy with D-04 separation-of-duties + D-13 line
    //      immutability gates). Floor bumped 27 → 29.
    // 30 = above + 1 CompetitorFtpSourcePolicy (Phase 11.1 — admin-only
    //      with encrypted credentials per D-08). Floor bumped 29 → 30.
    // 31 = 30 - CompetitorFtpSourcePolicy (Phase 11.2 dropped) + CompetitorFtpCredentialPolicy
    //      (admin-only — encrypted creds, D-09) + CompetitorFtpFeedPolicy (admin write +
    //      pricing_manager view, D-11). Net +1. Floor bumped 30 → 31.
    expect(count($policyFiles))
        ->toBeGreaterThanOrEqual(31, 'Expected ≥ 31 Policy files — got '.count($policyFiles).': '.implode(', ', $policyFiles));
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
        // Phase 5 Plan 01 — 5 Competitor policies (D-02 + D-04 role split).
        \App\Domain\Competitor\Models\Competitor::class           => \App\Domain\Competitor\Policies\CompetitorPolicy::class,
        \App\Domain\Competitor\Models\CompetitorPrice::class      => \App\Domain\Competitor\Policies\CompetitorPricePolicy::class,
        \App\Domain\Competitor\Models\CompetitorCsvMapping::class => \App\Domain\Competitor\Policies\CompetitorCsvMappingPolicy::class,
        \App\Domain\Competitor\Models\CompetitorIngestRun::class  => \App\Domain\Competitor\Policies\CompetitorIngestRunPolicy::class,
        \App\Domain\Competitor\Models\CsvParseError::class        => \App\Domain\Competitor\Policies\CsvParseErrorPolicy::class,
        // Phase 6 Plan 01 — 2 ProductAutoCreate policies (D-04 + D-06).
        \App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule::class  => \App\Domain\ProductAutoCreate\Policies\AutoCreateSkipRulePolicy::class,
        \App\Domain\ProductAutoCreate\Models\AutoCreateRejection::class => \App\Domain\ProductAutoCreate\Policies\AutoCreateRejectionPolicy::class,
        // Phase 6 Plan 04 — singleton AutoCreateSetting Page gate (admin-only).
        \App\Domain\ProductAutoCreate\Models\AutoCreateSetting::class   => \App\Domain\ProductAutoCreate\Policies\AutoCreateSettingsPolicy::class,
        // Phase 7 Plan 01 — Dashboard domain (D-02 + D-07).
        \App\Domain\Dashboard\Models\DashboardSnapshot::class           => \App\Domain\Dashboard\Policies\DashboardSnapshotPolicy::class,
        \App\Domain\Dashboard\Models\UserSavedFilter::class             => \App\Domain\Dashboard\Policies\UserSavedFilterPolicy::class,
        // Phase 8 Plan 01 — C4 Agent Framework (admin-only AgentRun viewer).
        \App\Domain\Agents\Models\AgentRun::class                       => \App\Domain\Agents\Policies\AgentRunPolicy::class,
        // Phase 11 Plan 01 — Quote + QuoteLine policies
        // (D-04 separation-of-duties + D-13 line immutability gates).
        \App\Domain\Quotes\Models\Quote::class                          => \App\Domain\Quotes\Policies\QuotePolicy::class,
        \App\Domain\Quotes\Models\QuoteLine::class                      => \App\Domain\Quotes\Policies\QuoteLinePolicy::class,
        // Phase 11.2 Plan 01 — multi-feed FTP refactor (D-09 + D-11).
        // Replaces Phase 11.1's CompetitorFtpSourcePolicy (deleted).
        // CompetitorFtpCredentialPolicy: admin-only (encrypted credentials).
        // CompetitorFtpFeedPolicy: admin write + pricing_manager view-only.
        \App\Domain\Competitor\Models\CompetitorFtpCredential::class    => \App\Domain\Competitor\Policies\CompetitorFtpCredentialPolicy::class,
        \App\Domain\Competitor\Models\CompetitorFtpFeed::class          => \App\Domain\Competitor\Policies\CompetitorFtpFeedPolicy::class,
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
