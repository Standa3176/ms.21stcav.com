<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Seo\ProposeContentPatchTool;

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 3 — ProposeContentPatchTool contract test
|--------------------------------------------------------------------------
|
| Validates SEOAGT-02 propose_content_patch no-op writer:
|   - name() === 'propose_content_patch'
|   - asPrismTool() returns a Prism Tool with the exact 5 parameter slots
|     (sku, field, before, after, reasoning) — Open Question O-1 resolved
|     YES: Prism v0.100.1 DOES support withEnumParameter (vendor/prism-php/
|     prism/src/Tool.php line 199), so `field` uses enum-typed param with
|     the 4 valid values pinned in the schema (tighter Anthropic surface).
|   - using() callable always returns {"acknowledged":true} regardless of
|     args — no side effects, no validation (mapper at Plan 12-04 enforces
|     field validity as defence in depth).
|
| Why no DB: pure Prism Tool builder + callable invocation.
*/

it('name() === "propose_content_patch"', function () {
    expect(app(ProposeContentPatchTool::class)->name())->toBe('propose_content_patch');
});

it('asPrismTool() returns a Prism Tool instance with exactly 5 parameters', function () {
    $tool = app(ProposeContentPatchTool::class)->asPrismTool();

    expect($tool)->toBeInstanceOf(\Prism\Prism\Tool::class);

    $params = array_keys($tool->parameters());
    expect($params)->toEqualCanonicalizing(['sku', 'field', 'before', 'after', 'reasoning']);
});

it('field parameter is enum-typed with the 4 valid SEO fields (Open Question O-1 resolved)', function () {
    $tool = app(ProposeContentPatchTool::class)->asPrismTool();
    $schema = $tool->parametersAsArray()['field'];

    // EnumSchema serialises as {"description":"...","enum":[...],"type":"string"}
    expect($schema)->toHaveKey('enum');
    expect($schema['enum'])->toEqualCanonicalizing([
        'title',
        'short_description',
        'long_description',
        'meta_description',
    ]);
});

it('using() callable returns {"acknowledged":true} regardless of args (no side effects)', function () {
    $result = app(ProposeContentPatchTool::class)->asPrismTool()->handle(
        'SKU-1',
        'short_description',
        'old value',
        'new value',
        'reasoning that satisfies the 20-char minimum cited in tool desc',
    );

    expect($result)->toBe('{"acknowledged":true}');
});

it('description contains the 4 field names verbatim (locked schema contract)', function () {
    $desc = app(ProposeContentPatchTool::class)->description();

    expect($desc)->toContain('title');
    expect($desc)->toContain('short_description');
    expect($desc)->toContain('long_description');
    expect($desc)->toContain('meta_description');
});
