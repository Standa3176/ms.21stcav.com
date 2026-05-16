<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 03 — SeoAgent guardrail regex pattern library
|--------------------------------------------------------------------------
|
| Source of truth for SeoOutboundGuardrail's post-flight regex chain
| (SEOAGT-04). The guardrail compiles the array once per run and applies it
| to the `before` + `after` text of every `propose_content_patch` tool call
| in the Prism response. First match → throws GuardrailViolationException
| with $failedPatternKey + $matchedExcerpt → RunSeoAgentJob (Plan 12-04)
| catch-block writes an `agent_guardrail_blocked` Suggestion via
| SeoAgentResultMapper::createGuardrailBlockedSuggestion(...). NO partial
| publishing — first match fails the entire run per CONTEXT D-01.
|
| Three categories ship in Plan 12-03 as a conservative starter set. Ops
| iterate via PR — git history IS the version history. See:
|   - .planning/phases/12-c3-seo-content-agent/12-RESEARCH.md
|     §Brand-Voice Regex Pattern Library for the design rationale
|   - resources/agents/brand-voice/_global.md "Words to avoid" — same
|     vocabulary scope but enforced at LLM-prompt level (cheaper, less
|     defence-in-depth than the post-flight regex chain here)
|
| Regex design choices:
|   1. Case-insensitive (`/i`) — marketing copy varies casing.
|   2. Word-boundary anchors (`\b`) — avoids false positives
|      ("cheapestest" wouldn't match "cheapest" without `\b`).
|   3. Flexible whitespace / hyphenation (`\s+`, `[\s-]?`) — covers
|      "game-changer", "game changer", "gamechanger".
|   4. Each pattern is consumed via $m[0] only — no nested capture groups.
|   5. ASCII word-boundary `\b` is fine for product copy; upgrade to /u
|      flag only if multi-byte boundary matching becomes necessary.
|
| Threat surface anchor:
|   - T-12-03-01 (price claim fabrication)
|   - T-12-03-02 (competitor product naming)
|   - T-12-03-03 (marketing superlatives)
|   - T-12-03-06 (malformed regex — accepted; @preg_match returns false
|     silently and the offending pattern is skipped; PR review is the
|     calibration loop; SeoAgentConfigTest gates compile errors at CI time)
*/
return [

    'guardrails' => [

        // SEOAGT-04 category 1: competitor brand names we should NOT mention
        // by name in MeetingStore copy. Mention is acceptable in technical
        // compatibility statements ("compatible with Zoom Rooms" — Zoom is a
        // SERVICE tier, not a competing hardware product), but invoking a
        // competitor HARDWARE product by name on our marketing copy is
        // forbidden. Plan 12-03 ships a conservative starter set; ops/PR
        // iteration adds more as they're spotted in production drafts.
        //
        // NB: 'Zoom', 'Microsoft Teams', 'Google Meet' explicitly NOT in
        // this list — they are platform/service names that legitimately
        // appear in compatibility statements. Only the COMPETING HARDWARE
        // products are forbidden.
        'competitor_brands' => [
            // Direct AV-vendor competitor product names
            '/\b(?:cisco\s+webex(?:\s+room)?)\b/i',
            '/\b(?:poly\s+studio)\b/i',
            '/\b(?:neat\s+(?:bar|board|frame))\b/i',
            '/\b(?:yealink\s+(?:meetingboard|meetingbar))\b/i',
        ],

        // SEOAGT-04 category 2: absolute price claims without supplier-data
        // backing. The agent has NO access to live supplier price data in
        // the SEO context — any absolute price claim would be fabricated.
        // ALL absolute-price language is forbidden.
        'price_claims_absolute' => [
            '/\b(?:cheapest|lowest\s+price|best\s+price|unbeatable\s+price|guaranteed\s+lowest)\b/i',
            '/\b(?:price\s+match(?:\s+guarantee)?)\b/i',
            '/\b(?:£\s*\d+(?:\.\d{2})?\s*(?:saving|off|less))\b/i',  // "£50 saving" / "£25 off" style
            '/\b(?:half\s+price|50%\s+off|massive\s+discount)\b/i',
        ],

        // SEOAGT-04 category 3: marketing superlatives outside the
        // MeetingStore factual brand voice (per _global.md "Words to avoid"
        // section). Defence-in-depth: the brand-voice document says "avoid
        // these"; the regex here BLOCKS them on the post-flight boundary
        // even if the LLM ignores the brand-voice guidance.
        'marketing_superlatives' => [
            '/\b(?:revolutionary|groundbreaking|game[\s-]?chang(?:er|ing)|paradigm[\s-]?shift)\b/i',
            '/\b(?:world[\'\x{2019}s]+\s+(?:best|first|leading|finest))\b/iu',
            '/\b(?:industry[\s-]?leading|cutting[\s-]?edge|state[\s-]?of[\s-]?the[\s-]?art)\b/i',
            '/\b(?:unparalleled|unmatched|incomparable|unrivalled)\b/i',
            '/\b(?:perfect(?:\s+solution)?|ultimate(?:\s+solution)?)\b/i',
        ],

    ],

];
