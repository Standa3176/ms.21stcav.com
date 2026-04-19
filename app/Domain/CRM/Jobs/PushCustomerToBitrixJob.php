<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\Alerting\Notifiables\AlertDistribution;
use App\Domain\CRM\Events\BitrixContactPushed;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Notifications\CrmPushFailedNotification;
use App\Domain\CRM\Services\ContactPayloadBuilder;
use App\Domain\CRM\Services\EntityDeduper;
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
 * Phase 4 Plan 03 Task 2 — Contact-only upsert for customer.* webhooks.
 *
 * Runs on the crm-bitrix queue. No Deal or Company touched — the customer
 * webhook fires before any purchase happens (D-04: attribution on
 * registered-but-not-yet-purchased leads).
 *
 * Same retry policy (D-11) + DLQ producer (D-12) + 5-min alert dedup as
 * PushOrderToBitrixJob.
 */
class PushCustomerToBitrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 120;

    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $topic,
    ) {
        $this->onQueue('crm-bitrix');
    }

    public function handle(
        EntityDeduper $deduper,
        ContactPayloadBuilder $contactBuilder,
    ): void {
        $receipt = WebhookReceipt::findOrFail($this->webhookReceiptId);
        $correlationId = (string) ($receipt->correlation_id ?? Context::get('correlation_id') ?? '');
        if ($correlationId !== '') {
            Context::add('correlation_id', $correlationId);
        }

        $payload = json_decode((string) $receipt->raw_body, true);
        if (! is_array($payload)) {
            throw new RuntimeException('PushCustomerToBitrixJob: malformed payload in receipt '.$receipt->id);
        }

        $wooCustomerId = (int) ($payload['id'] ?? 0);
        if ($wooCustomerId <= 0) {
            Log::warning('PushCustomerToBitrixJob: skipping — customer_id missing from payload', [
                'webhook_receipt_id' => $receipt->id,
                'correlation_id' => $correlationId,
            ]);

            return;
        }

        try {
            $contactPayload = $contactBuilder->build($payload, $correlationId);
            $contactId = (string) $deduper->findOrCreateContact($wooCustomerId, $contactPayload, $correlationId);
            event(new BitrixContactPushed($wooCustomerId, $contactId, 'upsert'));
        } catch (BitrixPermanentException $e) {
            $this->emitFailedSuggestion('permanent_validation', $wooCustomerId, $e->getMessage(), $correlationId, $payload);
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        $receipt = WebhookReceipt::find($this->webhookReceiptId);
        $payload = [];
        $correlationId = '';
        $wooCustomerId = 0;

        if ($receipt !== null) {
            $decoded = json_decode((string) $receipt->raw_body, true);
            $payload = is_array($decoded) ? $decoded : [];
            $correlationId = (string) ($receipt->correlation_id ?? '');
            $wooCustomerId = (int) ($payload['id'] ?? 0);
        }

        if (! $e instanceof BitrixPermanentException) {
            $this->emitFailedSuggestion('push_exhausted', $wooCustomerId, $e->getMessage(), $correlationId, $payload);
        }

        $lockKey = 'crm-push-customer-failed-alert:'.$wooCustomerId;
        if (Cache::add($lockKey, 1, now()->addMinutes(5))) {
            try {
                Notification::send(
                    new AlertDistribution(onlyReceiving: 'receives_crm_alerts'),
                    new CrmPushFailedNotification($wooCustomerId, $e->getMessage(), $correlationId !== '' ? $correlationId : null),
                );
            } catch (Throwable $notifyEx) {
                Log::error('PushCustomerToBitrixJob::notifyCrmAlerts failed', [
                    'woo_customer_id' => $wooCustomerId,
                    'error' => $notifyEx->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitFailedSuggestion(
        string $subKind,
        int $wooCustomerId,
        string $errorMessage,
        string $correlationId,
        array $payload,
    ): void {
        Suggestion::create([
            'kind' => 'crm_push_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
            'payload' => [
                'sub_kind' => $subKind,
                'entity_type' => 'contact',
                'woo_id' => $wooCustomerId,
                'topic' => $this->topic,
                'http_status' => 0,
                'error_message' => $errorMessage,
            ],
            'evidence' => [
                'correlation_id' => $correlationId !== '' ? $correlationId : null,
                'webhook_receipt_id' => $this->webhookReceiptId,
                'retry_count' => $this->attempts(),
                'request_payload' => $payload,
            ],
            'proposed_at' => now(),
        ]);
    }
}
