<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\Alerting\Notifiables\AlertDistribution;
use App\Domain\CRM\Events\BitrixCompanyPushed;
use App\Domain\CRM\Events\BitrixContactPushed;
use App\Domain\CRM\Events\BitrixDealPushed;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Notifications\CrmPushFailedNotification;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\CompanyPayloadBuilder;
use App\Domain\CRM\Services\ContactPayloadBuilder;
use App\Domain\CRM\Services\DealPayloadBuilder;
use App\Domain\CRM\Services\EntityDeduper;
use App\Domain\CRM\Services\OrderNoteSynchroniser;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Phase 4 Plan 03 Task 2 — the beating heart of Phase 4.
 *
 * Dispatched by HandleOrderReceived. Implements:
 *   - Company → Contact → Deal sequencing (Bitrix requires this order —
 *     crm.deal.add fails if Contact doesn't exist yet)
 *   - D-10 race guard: order.updated before order.created → release(30)
 *     up to 5 attempts, then `update_before_create` suggestion
 *   - D-09 narrow-patch: status change → UpdateDealStageJob; always append
 *     new notes via OrderNoteSynchroniser; never re-push other fields
 *   - D-11 retry: 3 attempts / [30s, 5m, 30m] backoff for BitrixTransientException
 *     (thrown by BitrixClient's 5xx/429/network lane); BitrixPermanentException
 *     (4xx/auth) fails immediately via $this->fail()
 *   - D-12 DLQ producer: both exhaustion + permanent failures write a
 *     `crm_push_failed` suggestion; failed() also fires an AlertDistribution
 *     mail to `receives_crm_alerts=true` recipients (5-min Cache::add dedup)
 *   - 3 domain events (BitrixCompanyPushed/ContactPushed/DealPushed) fire
 *     on each successful entity push so Phase 7 can render a timeline
 */
class PushOrderToBitrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int>  D-11: 30s / 5m / 30m */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 120;

    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $topic,
        public readonly int $updateMissRetries = 0,
    ) {
        $this->onQueue('crm-bitrix');
    }

    public function handle(
        EntityDeduper $deduper,
        DealPayloadBuilder $dealBuilder,
        ContactPayloadBuilder $contactBuilder,
        CompanyPayloadBuilder $companyBuilder,
        BitrixClient $client,
        OrderNoteSynchroniser $notes,
    ): void {
        $receipt = WebhookReceipt::findOrFail($this->webhookReceiptId);
        $correlationId = (string) ($receipt->correlation_id ?? Context::get('correlation_id') ?? '');
        if ($correlationId !== '') {
            Context::add('correlation_id', $correlationId);
        }

        $order = json_decode((string) $receipt->raw_body, true);
        if (! is_array($order) || empty($order['id'])) {
            throw new RuntimeException('PushOrderToBitrixJob: malformed payload in receipt '.$receipt->id);
        }

        $wooOrderId = (int) $order['id'];
        $wooCustomerId = (int) ($order['customer_id'] ?? 0);
        $billing = (array) ($order['billing'] ?? []);

        try {
            $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
                ->where('woo_id', $wooOrderId)
                ->first();

            // ── D-10 race guard: order.updated arrived before order.created ──
            if ($this->topic === 'order.updated' && $map === null) {
                if ($this->updateMissRetries < 5) {
                    self::dispatch($this->webhookReceiptId, $this->topic, $this->updateMissRetries + 1)
                        ->delay(now()->addSeconds(30));

                    return;
                }
                $this->emitFailedSuggestion(
                    'update_before_create',
                    'deal',
                    $wooOrderId,
                    0,
                    'BitrixEntityMap missing after 5 retries',
                    $correlationId,
                    $order,
                );

                return;
            }

            // ── Company (optional — only when billing.company non-empty) ──
            $companyId = '';
            $companyTitle = trim((string) ($billing['company'] ?? ''));
            if ($companyTitle !== '') {
                $companyPayload = $companyBuilder->build($billing, $correlationId);
                $companyId = (string) $deduper->findOrCreateCompany(
                    $companyTitle,
                    (string) ($billing['postcode'] ?? ''),
                    $companyPayload,
                    $correlationId,
                );
                event(new BitrixCompanyPushed($companyTitle, $companyId, 'upsert'));
            }

            // ── Contact (mandatory) ──
            $contactPayload = $contactBuilder->build($order, $correlationId);
            $contactId = (string) $deduper->findOrCreateContact($wooCustomerId, $contactPayload, $correlationId);
            event(new BitrixContactPushed($wooCustomerId, $contactId, 'upsert'));

            // ── Deal ──
            if ($map === null) {
                // order.created path: adopt existing (UF_CRM_WOO_ORDER_ID filter) or create.
                $adoptedId = $deduper->findDealByWooOrderId($wooOrderId, $correlationId);
                if ($adoptedId !== null) {
                    $dealId = $adoptedId;
                    $mode = 'adopted';
                } else {
                    $dealPayload = $dealBuilder->build($order, $contactId, $companyId, $correlationId);
                    $dealId = (string) $client->dealAdd($dealPayload, $correlationId);
                    $mode = 'created';
                }

                BitrixEntityMap::updateOrCreate(
                    ['entity_type' => BitrixEntityMap::ENTITY_DEAL, 'woo_id' => $wooOrderId],
                    [
                        'bitrix_id' => $dealId,
                        'last_status_snapshot' => (string) ($order['status'] ?? 'pending'),
                        'last_payload_hash' => hash('sha256', (string) json_encode($order)),
                        'last_correlation_id' => $correlationId,
                        'last_pushed_at' => now(),
                        'created_via' => BitrixEntityMap::VIA_PUSH,
                    ],
                );
                event(new BitrixDealPushed($wooOrderId, $dealId, $mode));

                return;
            }

            // ── order.updated path — map exists ──
            $dealId = $map->bitrix_id;
            $newStatus = (string) ($order['status'] ?? '');
            $oldStatus = (string) ($map->last_status_snapshot ?? '');

            if ($newStatus !== '' && $newStatus !== $oldStatus) {
                UpdateDealStageJob::dispatch(
                    $wooOrderId,
                    $newStatus,
                    $oldStatus,
                    (float) ($order['total'] ?? 0),
                    $correlationId,
                );
            }

            $notes->appendNewNotes($dealId, $order, $map, $correlationId);

            // Always refresh the map snapshot even when stage unchanged.
            $map->update([
                'last_status_snapshot' => $newStatus !== '' ? $newStatus : $map->last_status_snapshot,
                'last_payload_hash' => hash('sha256', (string) json_encode($order)),
                'last_correlation_id' => $correlationId,
                'last_pushed_at' => now(),
            ]);

            event(new BitrixDealPushed($wooOrderId, $dealId, 'updated'));
        } catch (BitrixPermanentException $e) {
            // D-11: 4xx/auth failures fail fast — no retries.
            $this->emitFailedSuggestion(
                'permanent_validation',
                'deal',
                $wooOrderId,
                0,
                $e->getMessage(),
                $correlationId,
                $order,
            );
            $this->fail($e);
        }
        // BitrixTransientException propagates to Laravel's retry machinery (tries=3, $backoff).
    }

    public function failed(Throwable $e): void
    {
        $receipt = WebhookReceipt::find($this->webhookReceiptId);
        $order = [];
        $correlationId = '';
        $wooOrderId = 0;

        if ($receipt !== null) {
            $decoded = json_decode((string) $receipt->raw_body, true);
            $order = is_array($decoded) ? $decoded : [];
            $correlationId = (string) ($receipt->correlation_id ?? '');
            $wooOrderId = (int) ($order['id'] ?? 0);
        }

        // Don't double-write a suggestion for fail-fast 4xx (already emitted in handle()).
        if (! $e instanceof BitrixPermanentException) {
            $this->emitFailedSuggestion(
                'push_exhausted',
                'deal',
                $wooOrderId,
                0,
                $e->getMessage(),
                $correlationId !== '' ? $correlationId : null,
                $order,
            );
        }

        $this->notifyCrmAlerts($wooOrderId, $e, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitFailedSuggestion(
        string $subKind,
        string $entityType,
        int $wooId,
        int $httpStatus,
        string $errorMessage,
        ?string $correlationId,
        array $payload,
    ): void {
        Suggestion::create([
            'kind' => 'crm_push_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => $correlationId,
            'payload' => [
                'sub_kind' => $subKind,
                'entity_type' => $entityType,
                'woo_id' => $wooId,
                'topic' => $this->topic,
                'http_status' => $httpStatus,
                'error_message' => $errorMessage,
            ],
            'evidence' => [
                'correlation_id' => $correlationId,
                'webhook_receipt_id' => $this->webhookReceiptId,
                'retry_count' => $this->attempts(),
                'request_payload' => $payload,
            ],
            'proposed_at' => now(),
        ]);
    }

    private function notifyCrmAlerts(int $wooOrderId, Throwable $e, string $correlationId): void
    {
        // Phase 1 D-13 pattern: 5-min Cache::add dedup per-order so a Bitrix outage
        // can't spam ops with N emails for the same order retried N times.
        $lockKey = 'crm-push-failed-alert:'.$wooOrderId;
        if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
            return;
        }

        try {
            Notification::send(
                new AlertDistribution(onlyReceiving: 'receives_crm_alerts'),
                new CrmPushFailedNotification($wooOrderId, $e->getMessage(), $correlationId !== '' ? $correlationId : null),
            );
        } catch (Throwable $notifyEx) {
            // Never let a notification failure mask the original exception in the DLQ.
            Log::error('PushOrderToBitrixJob::notifyCrmAlerts failed', [
                'woo_order_id' => $wooOrderId,
                'error' => $notifyEx->getMessage(),
            ]);
        }
    }
}
