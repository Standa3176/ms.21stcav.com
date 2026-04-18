<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Contracts;

use App\Domain\Suggestions\Models\Suggestion;

/**
 * Contract for any class that applies a Suggestion of a specific kind.
 *
 * Phase 1 ships the contract + StubApplier for kind='test'.
 * Phase 5 (first real producer) implements MarginChangeApplier for kind='margin_change'.
 * Phase 6 adds NewProductApplier for kind='new_product'.
 *
 * Implementations MUST be idempotent — the ApplySuggestionJob may be retried.
 *
 * Register via:
 *   app(SuggestionApplierResolver::class)->register('my_kind', MyApplier::class);
 * in AppServiceProvider::boot().
 */
interface SuggestionApplier
{
    /** Which `kind` values this applier handles. Return an array of kind strings. */
    public function supports(): array;

    /**
     * Execute the change described by the suggestion.
     *
     * @throws \Throwable on failure — job catches, flips status to 'failed', surfaces in inbox
     * @return array arbitrary result data (logged to integration_events.response_body)
     */
    public function apply(Suggestion $suggestion): array;
}
