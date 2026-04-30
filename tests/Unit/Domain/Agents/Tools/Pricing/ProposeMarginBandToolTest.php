<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ProposeMarginBandTool;
use App\Domain\Agents\Tools\Pricing\TruncatingTool;

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 02 Task 2 — ProposeMarginBandTool no-op contract test
|--------------------------------------------------------------------------
|
| Per CONTEXT D-06: ProposeMarginBandTool is a structured-contract output
| sink, NOT a writer. Its using() callable returns '{"acknowledged":true}'
| verbatim; Plan 10-04's PricingAgentResultMapper extracts the FINAL
| invocation's args from `agent_run.tool_calls[]` post-loop and merges
| into Suggestion.evidence.agent_*.
|
| Pinning the no-op contract prevents future regressions where someone
| accidentally adds a write-side-effect to the tool body (which would
| break the AgentsWriteOnlyViaSuggestionsTest architectural invariant
| AND the latest-call-wins idempotency the mapper depends on).
|
| The architectural invariant — ProposeMarginBandTool is the SOLE exemption
| from PricingToolsObserveSoftCapTest — is also pinned here as a sanity
| check that the cap-exemption logic stays correct.
*/

it('name() returns propose_margin_band', function () {
    expect(app(ProposeMarginBandTool::class)->name())->toBe('propose_margin_band');
});

it('description() instructs the model to stop after calling', function () {
    expect(app(ProposeMarginBandTool::class)->description())
        ->toContain('respond with one short sentence and stop');
});

it('invoking the Prism tool with valid args returns {"acknowledged":true}', function () {
    $result = app(ProposeMarginBandTool::class)->asPrismTool()->handle(
        'TEST-SKU',
        2050,
        'Test reasoning string for verification of contract surface (>=40 chars).',
        72,
        1980,
        2120,
    );
    expect($result)->toBe('{"acknowledged":true}');
});

it('does NOT extend TruncatingTool (no-op writer is cap-exempt)', function () {
    expect(is_subclass_of(ProposeMarginBandTool::class, TruncatingTool::class))->toBeFalse();
});

it('returns identical output on repeated invocations (idempotent)', function () {
    $tool = app(ProposeMarginBandTool::class)->asPrismTool();
    $first = $tool->handle('SKU', 2000, 'reasoning here meeting the 40-char minimum constraint.', 50, 1900, 2100);
    $second = $tool->handle('SKU', 2000, 'reasoning here meeting the 40-char minimum constraint.', 50, 1900, 2100);
    expect($first)->toBe($second);
});
