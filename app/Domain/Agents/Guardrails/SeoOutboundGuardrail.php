<?php

declare(strict_types=1);

namespace App\Domain\Agents\Guardrails;

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Phase 12 Plan 03 (SEOAGT-04) — post-flight SEO brand-voice regex guardrail.
 *
 * Scans every `propose_content_patch` tool call's `before` + `after` arguments
 * against config('seo_agent.guardrails') — the 3-category starter regex set
 * (competitor_brands / price_claims_absolute / marketing_superlatives). First
 * match short-circuits the entire run by throwing GuardrailViolationException
 * with $failedPatternKey + $matchedExcerpt populated. NO partial publishing
 * per CONTEXT D-01.
 *
 * Architectural placement:
 *   - Lives in SeoAgent::guardrails() AT INDEX 2 (after SensitiveFieldsStrip
 *     + OutboundRegexFilter — Phase 8 / Phase 10 inheritance). The order
 *     matters because Plan 12-04's RunSeoAgentJob catch-block expects the
 *     SEO guardrail's exception class to flow through GuardrailEngine's
 *     post-flight chain LAST so any base-framework violation surfaces first.
 *   - Does NOT inject SeoAgentResultMapper — per RESEARCH §Pattern 7 Option B
 *     the catching job (RunSeoAgentJob in Plan 12-04) is responsible for
 *     calling $mapper->createGuardrailBlockedSuggestion(...) using the
 *     exception's $failedPatternKey + $matchedExcerpt fields. This keeps
 *     the guardrail concerns pure (scan + throw) and the Suggestion-write
 *     concerns where they belong (the job orchestration layer).
 *
 * Threat-model anchors:
 *   - T-12-03-01 (price-claim fabrication) — `price_claims_absolute` category
 *   - T-12-03-02 (competitor product naming) — `competitor_brands` category
 *   - T-12-03-03 (marketing superlatives) — `marketing_superlatives` category
 *   - T-12-03-06 (malformed regex DoS) — @preg_match suppresses warnings AND
 *     returns false on compile error; the pattern is skipped silently and
 *     the loop moves on. SeoAgentConfigTest gates pattern compilability at
 *     CI time so a bad regex never lands in production.
 *
 * Why scan both `before` AND `after`:
 *   - `before` is the CURRENT value the agent copied from read_product_draft
 *     (verbatim per the system prompt). If the existing supplier copy
 *     already contains a forbidden phrase, the patch shouldn't propagate it.
 *   - `after` is the agent's proposed new value — the load-bearing scan.
 *   - Scanning both is cheap and gives defence-in-depth at zero extra cost.
 */
final class SeoOutboundGuardrail implements Guardrail
{
    public function isPreFlight(): bool
    {
        return false;
    }

    public function isPostFlight(): bool
    {
        return true;
    }

    public function shouldRun(TrustTier $tier): bool
    {
        // ALWAYS runs for SeoAgent regardless of tier — SEO is Trusted in
        // v2.0 but the brand-voice contract is enforced uniformly. If a
        // future agent kind ever borrows this guardrail at Untrusted tier,
        // it still fires.
        return true;
    }

    public function pre(array $input): array
    {
        return $input;
    }

    public function post(ClaudeResponse $response): ClaudeResponse
    {
        /** @var array<string, array<int, string>> $patterns */
        $patterns = (array) config('seo_agent.guardrails', []);

        // $response->steps is array|Collection — toArray-coerce so foreach
        // works regardless of underlying shape.
        $steps = is_array($response->steps)
            ? $response->steps
            : $response->steps->all();

        foreach ($steps as $step) {
            $toolCalls = property_exists($step, 'toolCalls') ? $step->toolCalls : [];
            $toolCalls = is_array($toolCalls) ? $toolCalls : $toolCalls->all();

            foreach ($toolCalls as $call) {
                if (! $call instanceof ToolCall) {
                    continue;
                }
                if ($call->name !== 'propose_content_patch') {
                    continue;
                }

                $args = $call->arguments();
                $before = (string) ($args['before'] ?? '');
                $after = (string) ($args['after'] ?? '');
                $textToScan = $before."\n".$after;

                foreach ($patterns as $key => $regexes) {
                    foreach ((array) $regexes as $regex) {
                        $matched = @preg_match((string) $regex, $textToScan, $m);

                        if ($matched === 1) {
                            $excerpt = (string) ($m[0] ?? '');

                            throw new GuardrailViolationException(
                                guardrailClass: self::class,
                                message: sprintf(
                                    'SEO guardrail matched pattern %s — excerpt: %s',
                                    (string) $key,
                                    mb_substr($excerpt, 0, 200),
                                ),
                                failedPatternKey: (string) $key,
                                matchedExcerpt: mb_substr($excerpt, 0, 200),
                            );
                        }
                    }
                }
            }
        }

        return $response;
    }
}
