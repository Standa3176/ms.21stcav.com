<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Services\Tools\Tool;

/**
 * Phase 12 Plan 01 — RELOCATED from app/Domain/Agents/Tools/Pricing/
 * to the shared app/Domain/Agents/Tools/ parent so Phase 12 SeoAgent +
 * Phase 14 ChatbotAgent + any future agent can extend the same 3-KB
 * soft-cap helper without copy/paste.
 *
 * Original responsibility (Phase 10 Plan 02 — CONTEXT D-05):
 *
 * Each subclass implements reduceLargestArray() to define how its payload
 * shrinks when the JSON-encoded size exceeds SOFT_CAP_BYTES:
 *   - margin_history: downsamples evenly (most-recent N entries first)
 *   - competitor_prices: caps per-competitor data_points
 *   - supplier_price_trend: trims oldest entries
 *   - sales_volume_90d: never truncates (single integer + timestamp payload)
 *
 * The cap is a SOFT signal — if a tool's reduced payload still exceeds
 * SOFT_CAP_BYTES, it returns the reduced payload anyway (better truncated
 * data than no data). The `_truncated:true` + `_total_available:N` hints
 * signal to the agent that data exists beyond the cap so it can reflect
 * cap-driven sparseness in confidence reasoning.
 *
 * Architecture-test gates:
 *   - PricingToolsObserveSoftCapTest — enforces every read_* Pricing tool
 *     extends this class.
 *   - TruncatingToolRelocationTest (Phase 12) — enforces the OLD FQCN
 *     (App\Domain\Agents\Tools\Pricing\TruncatingTool) no longer exists
 *     and that Phase 10 tools' parent class is the NEW FQCN.
 *
 * RESEARCH §P12-D documents the relocation rationale: TruncatingTool is
 * not Pricing-specific behaviour; it's a shared streaming-JSON cap helper
 * for every agent tool that returns variable-size DB result payloads.
 */
abstract class TruncatingTool extends Tool
{
    protected const SOFT_CAP_BYTES = 3072;

    /**
     * Encode payload + apply soft cap.
     *
     * Adds _truncated/_total_available hints to the payload when the encoded
     * JSON would otherwise exceed SOFT_CAP_BYTES. Subclass-specific reduction
     * happens via reduceLargestArray().
     *
     * @param  array<string, mixed>  $payload
     */
    protected function capJson(array $payload, int $totalAvailable): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) <= self::SOFT_CAP_BYTES) {
            return $json;
        }

        $reduced = $this->reduceLargestArray($payload, self::SOFT_CAP_BYTES);
        $reduced['_truncated'] = true;
        $reduced['_total_available'] = $totalAvailable;

        // Iteratively shrink until under cap or until no further reduction possible.
        $iterations = 0;
        while (strlen(json_encode($reduced, JSON_THROW_ON_ERROR)) > self::SOFT_CAP_BYTES && $iterations < 5) {
            $before = json_encode($reduced, JSON_THROW_ON_ERROR);
            $reduced = $this->reduceLargestArray($reduced, self::SOFT_CAP_BYTES);
            $reduced['_truncated'] = true;
            $reduced['_total_available'] = $totalAvailable;
            $after = json_encode($reduced, JSON_THROW_ON_ERROR);
            if ($before === $after) {
                break; // No further reduction possible — return as-is.
            }
            $iterations++;
        }

        return json_encode($reduced, JSON_THROW_ON_ERROR);
    }

    /**
     * Subclass-specific reduction strategy. Called when capJson() detects the
     * payload exceeds SOFT_CAP_BYTES. Implementations should drop the largest
     * array in the payload (downsample, trim, etc.) without losing the
     * top-level schema keys.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function reduceLargestArray(array $payload, int $maxBytes): array;
}
