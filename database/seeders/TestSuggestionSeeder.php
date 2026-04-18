<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Phase 1 acceptance fixture — seeds exactly one Suggestion with kind='test'.
 * firstOrCreate keyed on kind='test' so reseeding on every deploy is a no-op
 * (the seeded row stays pending until an admin explicitly approves/rejects it).
 *
 * Supports Success Criterion #6: "admin approves the seeded test suggestion".
 * StubApplier handles the apply() side — ApplySuggestionJob resolves it by kind.
 */
class TestSuggestionSeeder extends Seeder
{
    public function run(): void
    {
        Suggestion::firstOrCreate(
            ['kind' => 'test'],
            [
                'status' => Suggestion::STATUS_PENDING,
                'correlation_id' => (string) Str::uuid(),
                'payload' => ['message' => 'Phase 1 acceptance test suggestion'],
                'evidence' => ['source' => 'seeder', 'created_at' => now()->toIso8601String()],
                'proposed_at' => now(),
            ]
        );
    }
}
