<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 02 — SEOAGT-02 read_brand_style_guide real implementation.
 *
 * Per CONTEXT D-01 + D-02 + RESEARCH §Tool 2: reads MeetingStore brand-voice
 * markdown from `resources/agents/brand-voice/{brand-slug}.md` if present,
 * falling back to the mandatory `_global.md` so the agent ALWAYS has a
 * voice anchor — never null.
 *
 * SECURITY (T-12-01-02 / P12-H — XSS via Blade rendering):
 *   - Reads file content via file_get_contents — RAW BYTES.
 *   - Content is treated as OPAQUE STRING and serialised via json_encode.
 *   - NEVER passes the content through Blade::render or @include — the
 *     LLM sees the markdown as data, never as a template. The system prompt
 *     Blade (Plan 12-03) also does NOT @include this file — agent fetches
 *     via tool call at runtime.
 *
 * Cap logic (T-12-02-03 DoS mitigation):
 *   - Content capped at 3072 chars via mb_substr (matches the broader
 *     Phase 10 D-05 3-KB soft-cap convention).
 *   - _bytes hint reports total bytes of the FILE on disk (pre-cap) so the
 *     agent can know when the brand voice doc is large enough to be
 *     truncated mid-section.
 *
 * Schema returned (per RESEARCH §Tool 2):
 * {
 *   "brand": "logitech",
 *   "source": "per-brand" | "global",
 *   "content": "<markdown ≤ 3072 chars>",
 *   "_bytes": 2348
 * }
 *
 * Slug normalisation:
 *   - Input trimmed + lowercased so 'Logitech' / 'LOGITECH' / ' logitech '
 *     all route to logitech.md.
 *   - Empty string OR literal 'global' → global file.
 *   - Other inputs → per-brand if file exists, else global fallback.
 */
final class ReadBrandStyleGuideTool extends Tool
{
    private const CONTENT_CAP_CHARS = 3072;

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
            ->using(fn (string $brand): string => $this->execute($brand));
    }

    private function execute(string $brand): string
    {
        $slug = strtolower(trim($brand));
        $perBrandPath = resource_path("agents/brand-voice/{$slug}.md");
        $globalPath = resource_path('agents/brand-voice/_global.md');

        if ($slug !== '' && $slug !== 'global' && is_file($perBrandPath)) {
            $content = (string) file_get_contents($perBrandPath);

            return json_encode([
                'brand' => $slug,
                'source' => 'per-brand',
                'content' => mb_substr($content, 0, self::CONTENT_CAP_CHARS),
                '_bytes' => strlen($content),
            ], JSON_THROW_ON_ERROR);
        }

        $content = is_file($globalPath) ? (string) file_get_contents($globalPath) : '';

        return json_encode([
            'brand' => $slug === '' ? 'global' : $slug,
            'source' => 'global',
            'content' => mb_substr($content, 0, self::CONTENT_CAP_CHARS),
            '_bytes' => strlen($content),
        ], JSON_THROW_ON_ERROR);
    }
}
