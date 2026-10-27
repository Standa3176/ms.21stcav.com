<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

/**
 * Phase 12.1 Plan 03 (D-05/D-06) — outcome of the Cost / Predicted Margin
 * computation. costPence / predictedMarginPence are null whenever the order is
 * not fully costed (never partial, never 0).
 */
final readonly class OrderFinancialsResult
{
    /**
     * @param  list<string>  $missingSkus
     */
    public function __construct(
        public bool $complete,
        public ?int $costPence,
        public int $netSalesPence,
        public ?int $predictedMarginPence,
        public array $missingSkus,
    ) {}
}
