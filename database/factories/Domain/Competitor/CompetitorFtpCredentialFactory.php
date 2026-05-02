<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\CompetitorFtpCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpCredential factory.
 *
 * Defaults to SFTP on port 22 with a unique slug-style name. `password_encrypted`
 * is plaintext at the factory level — Eloquent's `'encrypted'` cast applies
 * AES-256 on save (D-03).
 *
 * @extends Factory<CompetitorFtpCredential>
 */
class CompetitorFtpCredentialFactory extends Factory
{
    protected $model = CompetitorFtpCredential::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'protocol' => CompetitorFtpCredential::PROTOCOL_SFTP,
            'host' => fake()->domainName(),
            'port' => 22,
            'username' => fake()->userName(),
            'password_encrypted' => 'plaintext-password-cast-encrypts',
            'private_key_encrypted' => null,
            'passphrase_encrypted' => null,
            'base_path' => '/feeds',
            'verify_ssl' => true,
            'is_active' => true,
        ];
    }

    public function ftp(): static
    {
        return $this->state(fn () => [
            'protocol' => CompetitorFtpCredential::PROTOCOL_FTP,
            'port' => 21,
        ]);
    }

    public function ftps(): static
    {
        return $this->state(fn () => [
            'protocol' => CompetitorFtpCredential::PROTOCOL_FTPS,
            'port' => 990,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
