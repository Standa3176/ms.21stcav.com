<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * Phase 8 Plan 01 — agent trust tier (Claude's Discretion).
 *
 * Passed as constructor arg to `RunsAsAgent::execute(input, TrustTier $tier)`
 * (Plan 03). GuardrailEngine reads the tier and selects pre-run guardrails
 * accordingly (Untrusted layers prompt-injection XML fencing + sensitive-
 * fields strip; Trusted skips both for performance).
 *
 *   - Trusted   — admin-triggered runs, internal data only (Pricing/SEO).
 *   - Mixed     — operator-triggered batch runs reading customer-bearing data.
 *   - Untrusted — public-facing input flows (Chatbot, Ad enrichment).
 *
 * Compile-time check via Pest test in Plan 03 — agent classes must declare
 * a static method returning a TrustTier.
 */
enum TrustTier: string
{
    case Trusted = 'trusted';
    case Mixed = 'mixed';
    case Untrusted = 'untrusted';
}
