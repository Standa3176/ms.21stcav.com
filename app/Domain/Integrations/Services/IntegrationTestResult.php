<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

/**
 * Phase 09.1 Plan 01 — IntegrationTestResult value object.
 *
 * Returned by every client's testConnection() method (D-11 dispatch contract).
 * TestIntegrationAction translates this into the per-row last_test_* writeback
 * + Filament notification.
 */
final readonly class IntegrationTestResult
{
    public function __construct(
        public bool $ok,
        public int $latencyMs,
        public ?string $error = null,
    ) {}

    public static function ok(int $latencyMs): self
    {
        return new self(true, $latencyMs, null);
    }

    public static function failed(string $error, int $latencyMs): self
    {
        return new self(false, $latencyMs, $error);
    }
}
