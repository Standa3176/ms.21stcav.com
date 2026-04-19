<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\CRM\Events\BitrixDealPushed;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Services\BitrixClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 Plan 03 Task 2 — D-09 narrow stage/opportunity patch.
 *
 * Dispatched by PushOrderToBitrixJob when a Woo `order.updated` delivers a
 * status change (compared against bitrix_entity_map.last_status_snapshot).
 * Always runs as a separate job so the stage-transition call is auditable in
 * isolation from the note-append work.
 *
 * Narrow patch: we ONLY set STAGE_ID + OPPORTUNITY. We do NOT re-push every
 * Deal field — that would stomp manual Bitrix edits a salesperson made (D-09
 * rationale).
 *
 * Shares the D-11 retry policy with the parent job (3 attempts / 30s / 5m / 30m).
 */
final class UpdateDealStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 60;

    public function __construct(
        public readonly int $wooOrderId,
        public readonly string $newWooStatus,
        public readonly string $oldWooStatus,
        public readonly float $orderTotal,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('crm-bitrix');
    }

    public function handle(BitrixClient $client): void
    {
        if ($this->correlationId !== null && $this->correlationId !== '') {
            Context::add('correlation_id', $this->correlationId);
        }

        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
            ->where('woo_id', $this->wooOrderId)
            ->first();

        if ($map === null) {
            Log::warning('UpdateDealStageJob: no bitrix_entity_map row — parent push must run first', [
                'woo_order_id' => $this->wooOrderId,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        $stageId = CrmStatusMapping::stageIdForStatus($this->newWooStatus);
        if ($stageId === null || $stageId === '') {
            Log::warning('UpdateDealStageJob: no stage mapping for Woo status', [
                'woo_order_id' => $this->wooOrderId,
                'new_status' => $this->newWooStatus,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        $client->dealUpdate(
            $map->bitrix_id,
            [
                'STAGE_ID' => $stageId,
                'OPPORTUNITY' => $this->orderTotal,
            ],
            $this->correlationId,
        );

        $map->update([
            'last_status_snapshot' => $this->newWooStatus,
            'last_correlation_id' => $this->correlationId ?? $map->last_correlation_id,
            'last_pushed_at' => now(),
        ]);

        event(new BitrixDealPushed($this->wooOrderId, $map->bitrix_id, 'stage_changed'));
    }
}
