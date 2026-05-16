<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 3 — Phase 12 verification (SEOAGT-01..05 traceability)
|--------------------------------------------------------------------------
|
| Asserts the full Phase 12 file surface exists at the locked FQCNs +
| smoke-checks the scheduled command registration. Each SEOAGT-* requirement
| maps to one or more files this test pins:
|
|   SEOAGT-01 — SeoAgent + RunSeoAgentJob
|   SEOAGT-02 — 4 tools + propose writer
|   SEOAGT-03 — SeoContentPatchApplier + EditAutoCreateReview sidebar
|   SEOAGT-04 — SeoOutboundGuardrail + config/seo_agent.php
|   SEOAGT-05 — RunSeoAgentBatchCommand + scheduled at agents:run-seo-batch
|
| Architecture test (not Feature) — no DB, no boot kernel for most assertions
| (the schedule:list one DOES need the kernel, but it's read-only). Uses
| expect()->toBeTrue for file existence + class loading.
*/

use Illuminate\Support\Facades\Artisan;

// ── SEOAGT-01 — SeoAgent kind + RunSeoAgentJob ─────────────────────────────

it('SEOAGT-01: SeoAgent class exists at app/Domain/Agents/Agents/SeoAgent.php', function () {
    $path = base_path('app/Domain/Agents/Agents/SeoAgent.php');
    expect(file_exists($path))->toBeTrue();
    expect(class_exists(\App\Domain\Agents\Agents\SeoAgent::class))->toBeTrue();
});

it('SEOAGT-01: RunSeoAgentJob exists with the locked dispatch signature', function () {
    $path = base_path('app/Domain/Agents/Jobs/RunSeoAgentJob.php');
    expect(file_exists($path))->toBeTrue();
    expect(class_exists(\App\Domain\Agents\Jobs\RunSeoAgentJob::class))->toBeTrue();

    // The locked public dispatch signature: dispatch($productId, $batchCorrelationId = null).
    // Verified by reflecting on the constructor parameter list.
    $ref = new ReflectionClass(\App\Domain\Agents\Jobs\RunSeoAgentJob::class);
    $ctor = $ref->getConstructor();
    $params = $ctor->getParameters();

    expect($params[0]->getName())->toBe('productId');
    expect($params[1]->getName())->toBe('batchCorrelationId');
    expect($params[1]->isOptional())->toBeTrue();
});

// ── SEOAGT-02 — 4 SeoAgent tools ──────────────────────────────────────────

it('SEOAGT-02: all 4 SeoAgent tools exist under app/Domain/Agents/Tools/Seo/', function () {
    $expected = [
        'ReadProductDraftTool',
        'ReadBrandStyleGuideTool',
        'ReadSimilarShippedProductsTool',
        'ProposeContentPatchTool',
    ];
    foreach ($expected as $tool) {
        $path = base_path("app/Domain/Agents/Tools/Seo/{$tool}.php");
        expect(file_exists($path))->toBeTrue();
    }
});

// ── SEOAGT-03 — SeoContentPatchApplier + Filament sidebar ─────────────────

it('SEOAGT-03: SeoContentPatchApplier exists at the locked FQCN', function () {
    $path = base_path('app/Domain/Agents/Appliers/SeoContentPatchApplier.php');
    expect(file_exists($path))->toBeTrue();
    expect(class_exists(\App\Domain\Agents\Appliers\SeoContentPatchApplier::class))->toBeTrue();
});

it('SEOAGT-03: EditAutoCreateReview declares the seoPatchesInfolist method (P12-F additive)', function () {
    $path = base_path('app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php');
    expect(file_exists($path))->toBeTrue();
    expect(method_exists(
        \App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages\EditAutoCreateReview::class,
        'seoPatchesInfolist',
    ))->toBeTrue();
});

// ── SEOAGT-04 — SeoOutboundGuardrail + config/seo_agent.php ───────────────

it('SEOAGT-04: SeoOutboundGuardrail exists at the locked FQCN', function () {
    $path = base_path('app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php');
    expect(file_exists($path))->toBeTrue();
    expect(class_exists(\App\Domain\Agents\Guardrails\SeoOutboundGuardrail::class))->toBeTrue();
});

it('SEOAGT-04: config/seo_agent.php exists and declares the guardrails array', function () {
    $path = base_path('config/seo_agent.php');
    expect(file_exists($path))->toBeTrue();

    $config = require $path;
    expect($config)->toBeArray()
        ->and($config)->toHaveKey('guardrails');
    expect($config['guardrails'])->toBeArray()
        ->and($config['guardrails'])->toHaveKey('competitor_brands')
        ->and($config['guardrails'])->toHaveKey('price_claims_absolute')
        ->and($config['guardrails'])->toHaveKey('marketing_superlatives');
});

// ── SEOAGT-05 — RunSeoAgentBatchCommand + schedule entry ──────────────────

it('SEOAGT-05: RunSeoAgentBatchCommand exists at the locked FQCN', function () {
    $path = base_path('app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php');
    expect(file_exists($path))->toBeTrue();
    expect(class_exists(\App\Domain\Agents\Console\Commands\RunSeoAgentBatchCommand::class))->toBeTrue();
});

it('SEOAGT-05: schedule:list includes agents:run-seo-batch', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('agents:run-seo-batch');
});

it('SEOAGT-05: routes/console.php contains the cron 30 4 * * * Europe/London entry', function () {
    $source = (string) file_get_contents(base_path('routes/console.php'));

    expect($source)
        ->toContain('agents:run-seo-batch')
        ->toContain("'30 4 * * *'")
        ->toContain("'Europe/London'")
        ->toContain('AGENT_SEO_BATCH_SCHEDULE_ENABLED');
});

it('SEOAGT-05: RolePermissionSeeder seeds the run_seo_agent permission for pricing_manager', function () {
    $source = (string) file_get_contents(base_path('database/seeders/RolePermissionSeeder.php'));

    // Two occurrences expected — one Permission::firstOrCreate, one in the
    // pricing_manager whereIn allow-list. (grep -c equivalent.)
    $count = substr_count($source, "'run_seo_agent'");

    expect($count)->toBeGreaterThanOrEqual(2);
});

// ── Open Question O-5 resolution gate ─────────────────────────────────────

it('O-5: SuggestionResource source contains the agent_guardrail_blocked filter literal', function () {
    $source = (string) file_get_contents(base_path('app/Domain/Suggestions/Filament/Resources/SuggestionResource.php'));

    expect($source)->toContain('agent_guardrail_blocked');
});
