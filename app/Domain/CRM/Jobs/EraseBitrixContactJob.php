<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\CRM\Services\GdprEraser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4 Plan 05 Task 2 — GDPR erasure background job (CRM-13).
 *
 * Dispatched by:
 *   - `php artisan gdpr:erase-bitrix-customer --email=…` after the operator
 *     types `ERASE` to confirm;
 *   - `EraseCustomerAction` Filament bulk action (admin-only).
 *
 * Runs on the `default` queue (low frequency — a GDPR request lands every
 * few months, not every few seconds — no need for a dedicated supervisor).
 *
 * `$tries = 1`: GDPR scrubs should not retry silently. A failed scrub needs
 * human review so we don't keep retrying against a broken Bitrix tenant.
 */
class EraseBitrixContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $email,
        public readonly ?int $actorId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(GdprEraser $eraser): void
    {
        $eraser->eraseByEmail($this->email, $this->actorId, $this->correlationId);
    }
}
