<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpFeed factory.
 *
 * UNIQUE local_filename — the unique() faker modifier prevents collisions
 * across factory invocations within a single test (matches D-01 schema constraint).
 *
 * Default state: active csv feed; `nearAutoDisable()` state pre-sets 2 failures
 * so a 3rd will auto-disable; `stale()` state pre-sets remote_file_date to 45
 * days ago so the Filament red-text rule fires.
 *
 * @extends Factory<CompetitorFtpFeed>
 */
class CompetitorFtpFeedFactory extends Factory
{
    protected $model = CompetitorFtpFeed::class;

    public function definition(): array
    {
        return [
            'competitor_id' => Competitor::factory(),
            'credential_id' => CompetitorFtpCredential::factory(),
            'remote_filename' => fake()->word().'.csv',
            'local_filename' => fake()->unique()->slug(2).'.csv',
            'format' => CompetitorFtpFeed::FORMAT_CSV,
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function tsv(): static
    {
        return $this->state(fn () => ['format' => CompetitorFtpFeed::FORMAT_TSV]);
    }

    public function zip(): static
    {
        return $this->state(fn () => ['format' => CompetitorFtpFeed::FORMAT_ZIP]);
    }

    public function txt(): static
    {
        return $this->state(fn () => ['format' => CompetitorFtpFeed::FORMAT_TXT]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Pre-set 2 consecutive failures so a 3rd will auto-disable (D-13 step 7). */
    public function nearAutoDisable(): static
    {
        return $this->state(fn () => ['consecutive_failures' => 2]);
    }

    /** Pre-set remote_file_date 45 days ago so red-text stale rule fires (D-10). */
    public function stale(): static
    {
        return $this->state(fn () => ['remote_file_date' => now()->subDays(45)]);
    }
}
