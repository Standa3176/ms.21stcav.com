<?php

declare(strict_types=1);

/**
 * Phase 10 Plan 03 — PricingAgent prompt determinism gate (RESEARCH §Versioning + sha256 hash).
 *
 * The PromptRenderer returns ['prompt' => string, 'hash' => sha256-hex].
 * AgentRun.system_prompt_hash persists the hash on every pricing run so ops
 * can group runs by prompt version: "show me every PricingAgent run that
 * used the rubric I shipped on Apr 30" -> WHERE system_prompt_hash = '...'
 * + cross-reference git log on resources/views/agents/pricing/system.blade.php.
 *
 * Determinism is the load-bearing invariant: if the rendered prompt has any
 * non-deterministic content (timestamps, random IDs, clock-driven data), the
 * hash drifts between renders and the forensic seam fails. This test locks
 * down two-render parity. If a future Blade edit introduces dynamic content
 * (e.g. {{ now() }}), this test fails — forcing a conscious choice between
 * keeping determinism or accepting drift in exchange for richer context.
 *
 * Per CONTEXT Claude's Discretion §System prompt design: git history IS the
 * version history. No DB-stored prompts; no UI editor. The sha256 column is
 * the only forensic surface — keep it stable.
 */

use App\Domain\Agents\Services\PromptRenderer;

it('PromptRenderer returns prompt + hash keys with hash matching sha256 of prompt', function () {
    $rendered = app(PromptRenderer::class)->render('pricing');

    expect($rendered)->toHaveKeys(['prompt', 'hash']);
    expect($rendered['prompt'])->toBeString();
    expect($rendered['hash'])->toBeString();
    expect($rendered['hash'])->toBe(hash('sha256', $rendered['prompt']));
});

it('rendering twice produces an identical hash (deterministic Blade output)', function () {
    // Two back-to-back renders must produce byte-identical hashes. If the
    // Blade view ever introduces dynamic content (timestamps, random IDs,
    // clock-driven data) this assertion fails — forcing a conscious decision
    // about the determinism trade-off.
    $first = app(PromptRenderer::class)->render('pricing')['hash'];
    $second = app(PromptRenderer::class)->render('pricing')['hash'];

    expect($first)->toBe($second);
});

it('hash is exactly 64 hex chars (sha256 hex)', function () {
    $hash = app(PromptRenderer::class)->render('pricing')['hash'];

    expect(strlen($hash))->toBe(64);
    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('rendered prompt is substantive (>800 chars + contains rubric anchors + propose_margin_band)', function () {
    // Sanity check: the prompt should be the full Plan 10-03 system.blade.php
    // (~4 KB of persona + workflow + rubric + few-shot examples). If someone
    // truncates the view, this guard catches it before the calibration test
    // would fail in a less-helpful way.
    $prompt = app(PromptRenderer::class)->render('pricing')['prompt'];

    expect(strlen($prompt))->toBeGreaterThan(800);
    expect($prompt)->toContain('0-30 LOW');
    expect($prompt)->toContain('31-70 MODERATE');
    expect($prompt)->toContain('71-100 HIGH');
    expect($prompt)->toContain('propose_margin_band');
});
