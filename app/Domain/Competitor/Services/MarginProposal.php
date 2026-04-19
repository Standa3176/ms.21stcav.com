<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

/**
 * Phase 5 Plan 03 Task 2 — readonly DTO returned by MarginAnalyser::computeProposal().
 *
 * Pure primitives — penny-exact integer arithmetic all the way through; no
 * Eloquent models, no floats. Consumers (ComputeMarginSuggestionJob) use it
 * to build the D-07 evidence JSON + populate Suggestion.payload.
 */
final readonly class MarginProposal
{
    public function __construct(
        public int $proposedMarginBasisPoints,
        public int $competitorExVatPennies,
        public int $supplierExVatPennies,
        public int $beatByPennies,
    ) {}
}
