<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 11.1 Plan 01 — CompetitorFtpSource factory.
 *
 * Defaults to SFTP on port 22 with valid Phase 5 filename regex + 15-min cron.
 * `password_encrypted` is plaintext at the factory level — Eloquent's
 * `'encrypted'` cast applies AES-256 on save (D-04).
 *
 * @extends Factory<CompetitorFtpSource>
 */
class CompetitorFtpSourceFactory extends Factory
{
    protected $model = CompetitorFtpSource::class;

    public function definition(): array
    {
        return [
            'competitor_id' => Competitor::factory(),
            'name' => fake()->slug(2),
            'protocol' => CompetitorFtpSource::PROTOCOL_SFTP,
            'host' => fake()->domainName(),
            'port' => 22,
            'username' => fake()->userName(),
            'password_encrypted' => 'plaintext-password-cast-encrypts',
            'private_key_encrypted' => null,
            'passphrase_encrypted' => null,
            'base_path' => '/',
            'filename_pattern' => '/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/',
            'cron_expression' => '*/15 * * * *',
            'verify_ssl' => true,
            'is_active' => true,
            'consecutive_failures' => 0,
            'last_pull_files_fetched' => 0,
        ];
    }

    public function ftp(): static
    {
        return $this->state(fn () => [
            'protocol' => CompetitorFtpSource::PROTOCOL_FTP,
            'port' => 21,
        ]);
    }

    public function ftps(): static
    {
        return $this->state(fn () => [
            'protocol' => CompetitorFtpSource::PROTOCOL_FTPS,
            'port' => 990,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function nearAutoDisable(): static
    {
        // Pre-set 2 consecutive failures so a 3rd will trip the auto-disable.
        return $this->state(fn () => [
            'consecutive_failures' => 2,
        ]);
    }
}
