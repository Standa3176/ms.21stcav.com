<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 2 — propose_marketing_action (advice-only sink)
|--------------------------------------------------------------------------
|
| The tool is a no-op structured-contract sink (mirrors ProposeMarginBandTool):
| the callable returns an ack; the mapper materialises the Suggestion post-run.
| action_type + confidence are enum-typed at the Prism schema level so the
| model cannot emit an out-of-set value.
*/

use App\Domain\Agents\Services\ToolBus;
use App\Domain\Agents\Services\Tools\Tool;
use App\Domain\Agents\Tools\Marketing\ProposeMarketingActionTool;

it('uses the propose_ prefix and is a plain Tool (no-op sink, not TruncatingTool)', function () {
    $tool = app(ProposeMarketingActionTool::class);
    expect($tool)->toBeInstanceOf(Tool::class);
    expect($tool->name())->toBe('propose_marketing_action');
});

it('passes the ToolBus naming gate', function () {
    app(ToolBus::class)->assertNameAllowed(app(ProposeMarketingActionTool::class));
})->throwsNoExceptions();

it('the using() callable returns an acknowledgement ack with no side effect', function () {
    $prismTool = app(ProposeMarketingActionTool::class)->asPrismTool();

    $result = $prismTool->handle(
        action_type: 'shift_budget',
        target: 'Paid Search / Brand UK',
        rationale: 'Organic already converts this term; shift budget to weak paid coverage on high-margin SKUs.',
        supporting_metrics: '{"sessions":1200,"transactions":3}',
        confidence: 'medium',
    );

    expect(json_decode($result, true))->toBe(['acknowledged' => true]);
});

it('exposes action_type as a Prism enum with exactly the 5 sanctioned action types', function () {
    $schema = app(ProposeMarketingActionTool::class)->asPrismTool()->parametersAsArray();

    expect($schema)->toHaveKey('action_type');
    expect($schema['action_type']['enum'])->toEqualCanonicalizing([
        'shift_budget',
        'increase_investment',
        'reduce_spend',
        'pause_target',
        'add_coverage',
    ]);
});

it('exposes confidence as a Prism enum and marks the evidence args required', function () {
    $prismTool = app(ProposeMarketingActionTool::class)->asPrismTool();
    $schema = $prismTool->parametersAsArray();

    expect($schema['confidence']['enum'])->toEqualCanonicalizing(['low', 'medium', 'high']);

    // action_type / target / rationale / confidence are required at the schema
    // level so the model cannot omit them (or emit an out-of-set enum value).
    expect($prismTool->requiredParameters())->toContain('action_type', 'target', 'rationale', 'confidence');
});
