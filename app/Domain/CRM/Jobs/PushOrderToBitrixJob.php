<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\Alerting\Notifiables\AlertDistribution;
use App\Domain\CRM\Events\BitrixCompanyPushed;
use App\Domain\CRM\Events\BitrixContactPushed;
use App\Domain\CRM\Events\BitrixDealPushed;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Notifications\CrmPushFailedNotification;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\BitrixSchemaCache;
use App\Domain\CRM\Services\CompanyPayloadBuilder;
use App\Domain\CRM\Services\ContactPayloadBuilder;
use App\Domain\CRM\Services\DealPayloadBuilder;
use App\Domain\CRM\Services\EntityDeduper;
use App\Domain\CRM\Services\FieldWhitelister;
use App\Domain\CRM\Services\OrderDealFinancials;
use App\Domain\CRM\Services\OrderFinancialsResult;
use App\Domain\CRM\Services\OrderNoteSynchroniser;
use App\Domain\CRM\Services\OrderProductRowBuilder;
use App\Domain\CRM\Support\BitrixFieldGuards;
use App\Domain\CRM\Support\OrderPaidGate;
use App\Domain\CRM\Support\ShadowId;
use App\Domain\Pricing\Services\OrderLineCostLookup;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
 *   - 12.1 D-01: deal created by the first paid-status webhook (order.created
 *     or order.updated); per-order cache lock prevents double create. Unpaid
 *     orders with no map produce zero Bitrix calls (D-02).
 *   - 12.1 D-15: optional create-floor — order.updated for plugin-era ids with
 *     no map never creates a deal (logged as crm.deal.legacy_unmapped).
 *   - SHADOW-* ids from shadow mode are never persisted into bitrix_entity_map.
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
 *   - 12.1 D-03/D-16: a newly created deal gets product rows (+ shipping row) via
 *     dealProductRowsSet, TAX_RATE derived per line. Adopted deals are left alone.
 *   - 12.1 D-04: on order.updated rows are re-set only when the line-items hash
 *     changed; a deal with no baseline hash (legacy) just gets a baseline.
 *   - 12.1 D-05/D-06/D-07: Cost + Predicted Margin from stored buy_price, written
 *     only to the CrmPipelineSetting codes; BOTH omitted (blanked on update) when
 *     any line is uncosted, flagged as crm.deal.cost_incomplete. Never 0, never
 *     Actual Margin.
 *   - 12.1 D-09: a required-field rejection while Cost/PM were blank fails fast as
 *     crm_push_failed sub_kind 'required_field_blank' (no retry, no 0 fallback).
 */
class PushOrderToBitrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int>  D-11: 30s / 5m / 30m */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 120;

    /** Seconds to wait for the per-order create lock before releasing the job. */
    public int $lockWaitSeconds = 20;

    /** Set when a create was attempted with Cost/PM blanked (D-06); read by the D-09 catch. */
    private ?OrderFinancialsResult $blankedFinancials = null;

    public function __construct(
        public readonly int $webhookReceiptId,
        public readonly string $topic,
        /** Ignored since 12.1 — retained so already-serialised jobs still unserialise. */
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
        ?OrderProductRowBuilder $rowBuilder = null,
        ?OrderDealFinancials $financials = null,
        ?OrderLineCostLookup $costLookup = null,
    ): void {
        $rowBuilder ??= app(OrderProductRowBuilder::class);
        $financials ??= app(OrderDealFinancials::class);
        $costLookup ??= app(OrderLineCostLookup::class);
        $this->blankedFinancials = null;

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
            $status = OrderPaidGate::normalise((string) ($order['status'] ?? ''));
            $paid = OrderPaidGate::isPaid($status, (array) config('services.bitrix.order_paid_statuses', []));

            $map = $this->realDealMap($wooOrderId);

            if ($map === null) {
                // 12.1 D-01/D-02: never-paid orders produce zero Bitrix calls.
                if (! $paid) {
                    $this->logEvent('crm.deal.skipped_unpaid', $wooOrderId, $status, $correlationId);

                    return;
                }

                // 12.1 D-15: plugin-era orders are never auto-created from order.updated.
                $floor = config('services.bitrix.order_create_floor_id');
                if ($this->topic === 'order.updated' && is_int($floor) && $floor > 0 && $wooOrderId <= $floor) {
                    Log::warning('PushOrderToBitrixJob: order.updated for legacy unmapped order skipped (create floor)', [
                        'woo_order_id' => $wooOrderId,
                        'floor' => $floor,
                        'correlation_id' => $correlationId,
                    ]);
                    $this->logEvent('crm.deal.legacy_unmapped', $wooOrderId, $status, $correlationId);

                    return;
                }

                try {
                    $map = Cache::lock('crm-order-push:'.$wooOrderId, 120)->block(
                        $this->lockWaitSeconds,
                        function () use ($wooOrderId, $wooCustomerId, $billing, $order, $correlationId, $deduper, $dealBuilder, $contactBuilder, $companyBuilder, $client, $rowBuilder, $financials, $costLookup): ?BitrixEntityMap {
                            // Re-read inside the lock: a concurrent worker may have created it.
                            $existing = $this->realDealMap($wooOrderId);
                            if ($existing !== null) {
                                return $existing;
                            }

                            $this->createDeal($wooOrderId, $wooCustomerId, $billing, $order, $correlationId, $deduper, $dealBuilder, $contactBuilder, $companyBuilder, $client, $rowBuilder, $financials, $costLookup);

                            return null;
                        },
                    );
                } catch (LockTimeoutException) {
                    $this->release(15);

                    return;
                }

                if ($map === null) {
                    return;
                }
                // A concurrent worker created the deal: fall through to the update path.
            }

            // ── update path — map exists (regardless of paid status, so paid→cancelled moves the deal) ──
            // Company/contact upsert preserved from pre-12.1 behaviour (mapped orders only).
            $this->upsertCompanyAndContact($wooCustomerId, $billing, $order, $correlationId, $deduper, $contactBuilder, $companyBuilder);

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

            // 12.1 D-04: product rows + Cost/PM only when line items changed.
            $this->syncProductRowsOnUpdate($map, $order, $correlationId, $client, $rowBuilder, $financials, $costLookup);

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
            // D-09: a required-field rejection while D-06 blanked Cost/PM is its own, alerting kind.
            $blank = $this->blankedFinancials;
            $requiredBlank = $blank !== null && preg_match('/required|обязател/iu', $e->getMessage()) === 1;
            $this->emitFailedSuggestion(
                $requiredBlank ? 'required_field_blank' : 'permanent_validation',
                'deal',
                $wooOrderId,
                0,
                $requiredBlank
                    ? 'Bitrix rejected deal: required field blank (D-06 left Cost/Predicted Margin empty). Missing buy_price for: '.implode(', ', $blank->missingSkus !== [] ? $blank->missingSkus : ['(no product lines)']).'. Resolve per 12.1 runbook D-09 section — do NOT write 0. Bitrix said: '.$e->getMessage()
                    : $e->getMessage(),
                $correlationId,
                $order,
            );
            $this->fail($e);
        }
        // BitrixTransientException propagates to Laravel's retry machinery (tries=3, $backoff).
    }

    /**
     * Company → Contact → Deal create sequence (called inside the per-order lock).
     *
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>  $order
     */
    private function createDeal(
        int $wooOrderId,
        int $wooCustomerId,
        array $billing,
        array $order,
        string $correlationId,
        EntityDeduper $deduper,
        DealPayloadBuilder $dealBuilder,
        ContactPayloadBuilder $contactBuilder,
        CompanyPayloadBuilder $companyBuilder,
        BitrixClient $client,
        OrderProductRowBuilder $rowBuilder,
        OrderDealFinancials $financials,
        OrderLineCostLookup $costLookup,
    ): void {
        [$companyId, $contactId] = $this->upsertCompanyAndContact($wooCustomerId, $billing, $order, $correlationId, $deduper, $contactBuilder, $companyBuilder);

        // ── Deal: adopt existing (UF_CRM_WOO_ORDER_ID filter) or create ──
        $adoptedId = $deduper->findDealByWooOrderId($wooOrderId, $correlationId);
        if ($adoptedId !== null) {
            $dealId = $adoptedId;
            $mode = 'adopted';
        } else {
            $dealPayload = $dealBuilder->build($order, $contactId, $companyId, $correlationId);
            // D-05/D-06/D-07: Cost + Predicted Margin (both or neither), only to the configured codes.
            $fin = $this->computeFinancials($order, $costLookup, $financials);
            $dealPayload = array_merge($dealPayload, $this->financialFields($fin, $order, $financials, false, $correlationId));
            if (! $fin->complete) {
                $this->blankedFinancials = $fin;
            }
            $dealId = (string) $client->dealAdd($dealPayload, $correlationId);
            $mode = 'created';
        }

        // Shadow-mode sentinel must never be persisted (would poison the map after the flip).
        if (ShadowId::is($dealId)) {
            // Shadow evidence for the 12.1-06 parity check: the rows SyncDiff shares this correlation id.
            $shadowRows = $rowBuilder->build($order);
            if ($shadowRows !== []) {
                $client->dealProductRowsSet(0, $shadowRows, $correlationId);
            }
            event(new BitrixDealPushed($wooOrderId, $dealId, 'shadow'));

            return;
        }

        $hash = $rowBuilder->lineItemsHash($order);
        $map = BitrixEntityMap::updateOrCreate(
            ['entity_type' => BitrixEntityMap::ENTITY_DEAL, 'woo_id' => $wooOrderId],
            [
                // Adopted deals get a baseline hash only (plugin rows stay); created deals get the
                // hash once the rows call is confirmed below, so a failed rows call is retried.
                'line_items_hash' => $mode === 'adopted' ? $hash : null,
                'bitrix_id' => $dealId,
                'last_status_snapshot' => (string) ($order['status'] ?? 'pending'),
                'last_payload_hash' => hash('sha256', (string) json_encode($order)),
                'last_correlation_id' => $correlationId,
                'last_pushed_at' => now(),
                'created_via' => BitrixEntityMap::VIA_PUSH,
            ],
        );

        if ($mode === 'created') {
            $rows = $rowBuilder->build($order);
            if ($rows !== []) {
                $client->dealProductRowsSet((int) $dealId, $rows, $correlationId);
            }
            $map->update(['line_items_hash' => $hash]);
        }
        event(new BitrixDealPushed($wooOrderId, $dealId, $mode));
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function computeFinancials(array $order, OrderLineCostLookup $costLookup, OrderDealFinancials $financials): OrderFinancialsResult
    {
        $lines = array_values(array_filter((array) ($order['line_items'] ?? []), fn ($l) => is_array($l) && (int) ($l['quantity'] ?? 0) > 0));
        if ($lines === []) {
            return new OrderFinancialsResult(false, null, 0, null, []);
        }
        $prices = $costLookup->buyPricesForSkus(array_map(fn (array $l) => (string) ($l['sku'] ?? ''), $lines));

        return $financials->compute($lines, $prices);
    }

    /**
     * Cost / Predicted Margin fields for the configured codes (D-05..D-07), filtered
     * through the deal schema whitelist. Logs the D-06 / forbidden-target events.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private function financialFields(OrderFinancialsResult $fin, array $order, OrderDealFinancials $financials, bool $forUpdate, string $correlationId): array
    {
        $wooOrderId = (int) ($order['id'] ?? 0);
        $status = (string) ($order['status'] ?? '');
        $pipeline = CrmPipelineSetting::query()->first();
        $costCode = $pipeline?->deal_cost_field;
        $marginCode = $pipeline?->deal_predicted_margin_field;

        foreach ([$costCode, $marginCode] as $code) {
            if ($code !== null && BitrixFieldGuards::isForbiddenTarget((string) $code)) {
                $this->logEvent('crm.deal.forbidden_field_skipped', $wooOrderId, $status, $correlationId, ['field' => (string) $code]);
            }
        }
        if (! $fin->complete && $fin->missingSkus !== []) {
            $this->logEvent('crm.deal.cost_incomplete', $wooOrderId, $status, $correlationId, ['missing_skus' => $fin->missingSkus]);
        }

        $schema = app(BitrixSchemaCache::class);
        $fields = $financials->fieldsFor(
            $fin,
            $costCode,
            $marginCode,
            $schema->fieldsFor(BitrixSchemaCache::ENTITY_DEAL),
            (string) ($order['currency'] ?? 'GBP'),
            $forUpdate,
        );
        if ($fields === []) {
            return [];
        }

        return FieldWhitelister::filter($fields, $schema, app(IntegrationLogger::class), BitrixSchemaCache::ENTITY_DEAL, 'crm.deal.builder', $correlationId !== '' ? $correlationId : null);
    }

    /**
     * D-04: re-set product rows (and recompute Cost/PM) only when the line-items hash
     * changed. A legacy/adopted deal without a baseline just gets one. A push-created
     * deal without a hash means the rows call never confirmed, so it counts as changed.
     *
     * @param  array<string, mixed>  $order
     */
    private function syncProductRowsOnUpdate(
        BitrixEntityMap $map,
        array $order,
        string $correlationId,
        BitrixClient $client,
        OrderProductRowBuilder $rowBuilder,
        OrderDealFinancials $financials,
        OrderLineCostLookup $costLookup,
    ): void {
        $hash = $rowBuilder->lineItemsHash($order);
        $stored = $map->line_items_hash;

        if ($stored === $hash) {
            return;
        }
        if ($stored === null && $map->created_via !== BitrixEntityMap::VIA_PUSH) {
            $map->update(['line_items_hash' => $hash]);

            return;
        }

        $rows = $rowBuilder->build($order);
        if ($rows !== []) {
            $client->dealProductRowsSet((int) $map->bitrix_id, $rows, $correlationId);
        }

        $fin = $this->computeFinancials($order, $costLookup, $financials);
        $fields = $this->financialFields($fin, $order, $financials, true, $correlationId);
        if ($fields !== []) {
            $client->dealUpdate((string) $map->bitrix_id, $fields, $correlationId);
        }

        $map->update(['line_items_hash' => $hash]);
    }

    /**
     * Company (optional) then Contact (mandatory) upsert. Runs for creates and,
     * as before 12.1, for mapped-order updates.
     *
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>  $order
     * @return array{0: string, 1: string} [companyId, contactId]
     */
    private function upsertCompanyAndContact(
        int $wooCustomerId,
        array $billing,
        array $order,
        string $correlationId,
        EntityDeduper $deduper,
        ContactPayloadBuilder $contactBuilder,
        CompanyPayloadBuilder $companyBuilder,
    ): array {
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

        $contactPayload = $contactBuilder->build($order, $correlationId);
        $contactId = (string) $deduper->findOrCreateContact($wooCustomerId, $contactPayload, $correlationId);
        event(new BitrixContactPushed($wooCustomerId, $contactId, 'upsert'));

        return [$companyId, $contactId];
    }

    /** Deal map row, treating SHADOW-* ids as absent. */
    private function realDealMap(int $wooOrderId): ?BitrixEntityMap
    {
        $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
            ->where('woo_id', $wooOrderId)
            ->first();

        return ($map !== null && ShadowId::is((string) $map->bitrix_id)) ? null : $map;
    }

    private function logEvent(string $operation, int $wooOrderId, string $status, string $correlationId, array $extra = []): void
    {
        app(IntegrationLogger::class)->log([
            'channel' => 'bitrix',
            'direction' => 'outbound',
            'method' => 'POST',
            'operation' => $operation,
            'endpoint' => 'crm.deal.add',
            'request_body' => array_merge(['woo_order_id' => $wooOrderId, 'status' => $status, 'topic' => $this->topic], $extra),
            'http_status' => 0,
            'status' => 'success',
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
        ]);
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
