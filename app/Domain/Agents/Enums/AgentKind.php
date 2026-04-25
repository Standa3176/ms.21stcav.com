<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * Phase 8 Plan 01 — agent kind discriminator (D-06).
 *
 * - echo            — Phase 8 framework smoke-test agent (Claude's Discretion).
 *                     Single tool: `read_health_check`. Deleted in Phase 10.
 * - pricing         — Phase 10 PricingAgent (margin_change enrichment).
 * - seo             — Phase 12 SeoAgent (content patches for AutoCreate drafts).
 * - chatbot         — Phase 14 ProductFinderAgent (public REST chat surface).
 * - ad_optimisation — Phase 15 AdAgent (UTM/GCLID-driven optimisation).
 *
 * Order is contract-stable — `AgentRunTest` asserts the case sequence.
 */
enum AgentKind: string
{
    case Echo = 'echo';
    case Pricing = 'pricing';
    case Seo = 'seo';
    case Chatbot = 'chatbot';
    case AdOptimisation = 'ad_optimisation';
}
