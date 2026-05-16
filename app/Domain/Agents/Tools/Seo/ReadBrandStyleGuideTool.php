<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 01 — STUB for SEOAGT-02 read_brand_style_guide tool.
 *
 * Per CONTEXT D-01 + D-02 + RESEARCH §Tool 2: reads MeetingStore brand-voice
 * markdown from `resources/agents/brand-voice/{brand-slug}.md` if present,
 * falling back to the mandatory `_global.md` so the agent ALWAYS has a
 * voice anchor — never null.
 *
 * Plan 12-01 ships compile-time STUBS only — Plan 12-02 replaces the
 * `using()` body with the real per-brand + global fallback file-read logic
 * per the RESEARCH §Tool 2 example. The mandatory `_global.md` is shipped
 * in THIS plan so the stub never has a "global file missing" path even
 * after Plan 12-02 lands the real body.
 *
 * Stub-marker payload: `{"stub":true}` — Plan 12-02 contract test asserts
 * the body changes shape (returns the documented `{brand, source, content,
 * _bytes}` schema instead).
 *
 * SECURITY (T-12-01-02 Information Disclosure): the real body in Plan 12-02
 * MUST use `file_get_contents` + `json_encode` only — NEVER
 * `Blade::render($content)`. Brand-voice markdown is treated as data, not
 * a template.
 */
final class ReadBrandStyleGuideTool extends Tool
{
    public function name(): string
    {
        return 'read_brand_style_guide';
    }

    public function description(): string
    {
        return 'Read the MeetingStore brand voice rules for a brand. Returns per-brand markdown if a file exists at resources/agents/brand-voice/{brand-slug}.md, else falls back to the global voice file. ALWAYS returns content — never null.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('brand', 'Brand slug (e.g. "logitech") or "global" to fetch base voice')
            ->using(fn (string $brand): string => json_encode(['stub' => true], JSON_THROW_ON_ERROR));
    }
}
