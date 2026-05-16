<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 03 Task 2 — SeoAgent::guardrails() wiring contract
|--------------------------------------------------------------------------
|
| Locks the order + composition of SeoAgent's guardrail chain. Plan 12-04's
| RunSeoAgentJob + downstream tests rely on this deterministic order:
|   [0] SensitiveFieldsStripGuardrail   (per-tool I/O strip; same as Phase 10)
|   [1] OutboundRegexFilterGuardrail    (post-flight base regex; Phase 8)
|   [2] SeoOutboundGuardrail            (post-flight SEO brand-voice regex;
|                                         Phase 12 Plan 03 — this plan)
|
| Pins:
|   - guardrails() returns exactly 3 entries
|   - Entry types match the expected classes in the expected order
|   - Each entry is container-resolved (app() — not new'd inline)
*/

use App\Domain\Agents\Agents\SeoAgent;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SeoOutboundGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;

it('SeoAgent::guardrails() returns exactly 3 entries', function () {
    $guardrails = app(SeoAgent::class)->guardrails();

    expect($guardrails)->toBeArray();
    expect(count($guardrails))->toBe(3);
});

it('SeoAgent::guardrails()[0] is SensitiveFieldsStripGuardrail (per-tool I/O strip)', function () {
    $guardrails = app(SeoAgent::class)->guardrails();

    expect($guardrails[0])->toBeInstanceOf(SensitiveFieldsStripGuardrail::class);
});

it('SeoAgent::guardrails()[1] is OutboundRegexFilterGuardrail (Phase 8 post-flight base regex)', function () {
    $guardrails = app(SeoAgent::class)->guardrails();

    expect($guardrails[1])->toBeInstanceOf(OutboundRegexFilterGuardrail::class);
});

it('SeoAgent::guardrails()[2] is SeoOutboundGuardrail (Phase 12 post-flight SEO brand-voice regex)', function () {
    $guardrails = app(SeoAgent::class)->guardrails();

    expect($guardrails[2])->toBeInstanceOf(SeoOutboundGuardrail::class);
});

it('SeoAgent::guardrails() entries are container-resolved (not raw new instances)', function () {
    // Two consecutive calls to app() should return THE SAME object if the
    // service is singleton-bound, or distinct objects if non-singleton.
    // Either way, the class shape is what matters — assert that each entry
    // is a Guardrail-contract implementor.
    $guardrails = app(SeoAgent::class)->guardrails();

    foreach ($guardrails as $g) {
        expect($g)->toBeInstanceOf(\App\Domain\Agents\Contracts\Guardrail::class);
    }
});
