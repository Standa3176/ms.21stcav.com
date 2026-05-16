<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 03 Task 1 — SeoAgent system prompt determinism + content gate
|--------------------------------------------------------------------------
|
| Locks the rendered Blade output of resources/views/agents/seo/system.blade.php
| against:
|   - 5 anchor section headings (workflow / brand voice rules / forbidden output /
|     output contract / few-shot examples)
|   - Mandatory string occurrences (propose_content_patch ×5+, RightSense,
|     Logitech MeetUp, Zoom Rooms)
|   - sha256 hash shape (PromptRenderer contract — 64 hex chars)
|   - Deterministic re-render (two renders -> identical hash)
|   - P12-H defence at render boundary — Blade view must NOT @include the
|     brand voice markdown (agent fetches via read_brand_style_guide tool)
|
| Pattern mirrors tests/Feature/Agents/PricingAgentPromptHashTest.php
| (Phase 10 Plan 03 prompt determinism gate).
*/

use App\Domain\Agents\Services\PromptRenderer;

it('PromptRenderer returns prompt + hash keys for "seo" with hash matching sha256 of prompt', function () {
    $rendered = app(PromptRenderer::class)->render('seo', [
        'product_id' => 1,
        'sku' => 'TEST',
        'brand_slug' => 'logitech',
    ]);

    expect($rendered)->toHaveKeys(['prompt', 'hash']);
    expect($rendered['prompt'])->toBeString();
    expect($rendered['hash'])->toBeString();
    expect($rendered['hash'])->toBe(hash('sha256', $rendered['prompt']));
});

it('rendering twice produces an identical hash (deterministic Blade output)', function () {
    $first = app(PromptRenderer::class)->render('seo')['hash'];
    $second = app(PromptRenderer::class)->render('seo')['hash'];

    expect($first)->toBe($second);
});

it('hash is exactly 64 hex chars (sha256 hex)', function () {
    $hash = app(PromptRenderer::class)->render('seo')['hash'];

    expect(strlen($hash))->toBe(64);
    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('prompt contains all 5 anchor section headings', function () {
    $prompt = app(PromptRenderer::class)->render('seo')['prompt'];

    expect($prompt)->toContain('Your workflow');
    expect($prompt)->toContain('Brand voice rules');
    expect($prompt)->toContain('Forbidden output');
    expect($prompt)->toContain('Output contract');
    expect($prompt)->toContain('Few-shot examples');
});

it('prompt mentions propose_content_patch at least 5 times', function () {
    $prompt = app(PromptRenderer::class)->render('seo')['prompt'];
    $count = substr_count($prompt, 'propose_content_patch');

    expect($count)->toBeGreaterThanOrEqual(5);
});

it('prompt contains the few-shot example anchors (RightSense / Logitech MeetUp / Zoom Rooms)', function () {
    $prompt = app(PromptRenderer::class)->render('seo')['prompt'];

    expect($prompt)->toContain('RightSense');
    expect($prompt)->toContain('Logitech MeetUp');
    expect($prompt)->toContain('Zoom Rooms');
});

it('prompt is substantive (≥ 4096 chars — persona + workflow + voice rules + forbidden + contract + 2 examples)', function () {
    $prompt = app(PromptRenderer::class)->render('seo')['prompt'];

    expect(strlen($prompt))->toBeGreaterThanOrEqual(4096);
});

it('Blade view source does NOT @include brand voice markdown (P12-H — agent fetches via tool)', function () {
    // Read the raw Blade source file directly — verify zero @include directives
    // for brand-voice content. The agent gets voice via read_brand_style_guide(),
    // never inlined into the system prompt template.
    $bladePath = resource_path('views/agents/seo/system.blade.php');

    expect(is_file($bladePath))->toBeTrue();

    $source = (string) file_get_contents($bladePath);

    expect($source)->not->toContain('@include');
});
