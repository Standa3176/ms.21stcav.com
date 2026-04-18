<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Services;

use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use RuntimeException;

/**
 * Registry mapping suggestion.kind strings to applier class names.
 *
 * Bound as a singleton in the container (AppServiceProvider). Producers register
 * their kind during boot(), and ApplySuggestionJob resolves the right applier at run time.
 */
final class SuggestionApplierResolver
{
    /** @var array<string, class-string<SuggestionApplier>>  map of kind => applier class */
    private array $registry = [];

    public function register(string $kind, string $applierClass): void
    {
        $this->registry[$kind] = $applierClass;
    }

    public function resolve(Suggestion $suggestion): SuggestionApplier
    {
        $class = $this->registry[$suggestion->kind] ?? throw new RuntimeException(
            "No SuggestionApplier registered for kind: {$suggestion->kind}"
        );

        return app($class);
    }

    /** Useful for diagnostics / admin inspector page (Phase 7). */
    public function registered(): array
    {
        return $this->registry;
    }
}
