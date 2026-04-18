<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Appliers;

use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * No-op applier for kind='test' — Phase 1 acceptance fixture only.
 *
 * Does NOT touch external systems. Just records that apply() was invoked.
 * The existence of this class is what makes "admin approves the seeded test suggestion"
 * verifiable without Plan 05 / Phase 5's real producers existing yet.
 */
final class StubApplier implements SuggestionApplier
{
    public function supports(): array
    {
        return ['test'];
    }

    public function apply(Suggestion $suggestion): array
    {
        return [
            'applied_at' => now()->toIso8601String(),
            'applier' => self::class,
            'stub_result' => 'ok',
            'suggestion_id' => $suggestion->id,
        ];
    }
}
