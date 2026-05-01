<?php

declare(strict_types=1);

namespace App\Domain\CRM\Jobs;

use App\Domain\Alerting\Notifiables\AlertDistribution;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Notifications\QuotePushFailedNotification;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\EntityDeduper;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Phase 11 Plan 04 — push a Quote → Bitrix Deal (QUOT-05 + QUOT-06 + QUOT-07).
 *
 * Clones the Phase 4 PushOrderToBitrixJob shape verbatim (D-11 retry policy,
 * D-12 DLQ pattern). Differences from Phase 4 PushOrderToBitrixJob:
 *   - Keys off Quote.id (ULID) instead of Woo order_id (int)
 *   - BitrixEntityMap.entity_type='quote_deal' instead of 'deal'
 *   - Suggestion.kind='quote_push_failed' (new producer kind)
 *   - AlertRecipient.receives_quote_alerts (new opt-in column from Plan 11-01)
 *   - Calls dealAdd → dealProductRowsSet (NOT just dealAdd) so the Bitrix UI
 *     shows itemised line products (RESEARCH §11 verified SDK shape)
 *
 * Flow:
 *   1. Resolve Quote with lines via findOrFail (fail loud if quote vanished)
 *   2. Resolve / create Bitrix Contact via EntityDeduper::findOrCreateContact
 *      (OQ-4 — uses sentinel woo_customer_id=0 for quote contacts)
 *   3. Build Deal payload (TITLE, OPPORTUNITY=total/100, CURRENCY_ID=GBP,
 *      TYPE_ID=config('quote.bitrix_deal_type_id'), CONTACT_ID, custom field
 *      UF_CRM_WOO_QUOTE_ID=$quote->id)
 *   4. Lookup BitrixEntityMap on (entity_type='quote_deal', quote_id=$quote->id)
 *   5a. Map MISSING (first push): dealAdd → store new map → dealProductRowsSet
 *   5b. Map EXISTS (re-approval): dealUpdate same Deal → dealProductRowsSet
 *       (idempotent — same lines replace same lines per QUOT-07)
 *
 * Shadow-mode: BitrixClient layer routes everything to sync_diffs with
 * provider='bitrix' when CRM_WRITE_ENABLED=false. Phase 11 layers ON TOP a
 * SECOND gate `quote.bitrix_push_enabled` — when false, this Job writes a
 * sync_diff with provider='bitrix-quote' BEFORE delegating to BitrixClient
 * (which itself short-circuits via CRM_WRITE_ENABLED). Two gates protect
 * v1 cutover: ops can keep CRM_WRITE_ENABLED=true (Phase 4 orders flow live)
 * but QUOTE_BITRIX_PUSH_ENABLED=false (Phase 11 quotes still shadow).
 *
 * D-11 retry: 3 attempts / [30s, 5m, 30m] backoff. 4xx fails fast
 * (BitrixPermanentException). Transient errors retry per Laravel queue.
 *
 * D-12 DLQ: failed() hook + handle()-catch-permanent both write a
 * Suggestion(kind='quote_push_failed') + 5-min Cache::add deduped
 * AlertDistribution mail to receives_quote_alerts=true recipients.
 */
final class PushQuoteToBitrixDealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int>  Phase 4 D-11 inherited: 30s / 5m / 30m */
    public array $backoff = [30, 300, 1800];

    public int $timeout = 120;

    public function __construct(
        public readonly string $quoteId,
        public readonly string $correlationId,
    ) {
        // PHP 8.4 trait collision guard — never declare `public $queue`; use
        // ->onQueue() so SerializesModels doesn't fight Queueable's defaults.
        $this->onQueue('crm-bitrix');
    }

    public function handle(
        BitrixClient $client,
        PriceCalculator $calc,
        EntityDeduper $deduper,
    ): void {
        if ($this->correlationId !== '') {
            Context::add('correlation_id', $this->correlationId);
        }

        $quote = Quote::with('lines')->findOrFail($this->quoteId);

        // ── Phase 11 shadow-mode gate (separate from Phase 4 CRM_WRITE_ENABLED) ──
        // QUOTE_BITRIX_PUSH_ENABLED=false → write sync_diff with
        // provider='bitrix-quote' and return. NEVER call BitrixClient methods.
        if (! (bool) config('quote.bitrix_push_enabled', false)) {
            \App\Domain\Sync\Models\SyncDiff::create([
                'provider' => 'bitrix-quote',
                'channel' => 'bitrix',
                'method' => 'POST',
                'endpoint' => 'crm.deal.add+productrows.set',
                'woo_id' => null,
                'payload' => [
                    'quote_id' => $quote->id,
                    'quote_ulid_short' => $quote->ulidShort(),
                    'customer_email' => $quote->customer_email,
                    'total_pence_at_quote' => (int) $quote->total_pence_at_quote,
                    'type_id' => (string) config('quote.bitrix_deal_type_id', 'QUOTE'),
                    'rows' => $this->buildLineRows($quote, $calc),
                ],
                'correlation_id' => $this->correlationId,
                'created_at' => now(),
                'status' => 'pending',
            ]);

            Log::info('PushQuoteToBitrixDealJob: shadow-mode write to sync_diffs', [
                'quote_id' => $quote->id,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        try {
            // ── Step 1: Resolve / create Bitrix Contact ────────────────────
            // OQ-4 — quote contacts use sentinel woo_customer_id=0 (Phase 4
            // EntityDeduper map is keyed off woo_customer_id; passing 0 forces
            // the email-dedup path which is exactly what we want here — find
            // by EMAIL via crm.duplicate.findbycomm or create a new Contact).
            $contactPayload = [
                'EMAIL' => [['VALUE' => $quote->customer_email, 'VALUE_TYPE' => 'WORK']],
                'NAME' => $quote->customer_name ?? $quote->customer_email,
            ];
            $contactId = $deduper->findOrCreateContact(0, $contactPayload, $this->correlationId);

            // ── Step 2: Build Deal payload ─────────────────────────────────
            $dealPayload = $this->buildDealPayload($quote, (string) $contactId);

            // ── Step 3: Lookup existing BitrixEntityMap ────────────────────
            $map = BitrixEntityMap::query()
                ->where('entity_type', BitrixEntityMap::ENTITY_QUOTE_DEAL)
                ->where('quote_id', $quote->id)
                ->first();

            if ($map === null) {
                // First push — dealAdd then map insert.
                $dealId = (string) $client->dealAdd($dealPayload, $this->correlationId);

                BitrixEntityMap::create([
                    'entity_type' => BitrixEntityMap::ENTITY_QUOTE_DEAL,
                    'woo_id' => 0, // sentinel — quote_deal rows use quote_id, not woo_id
                    'quote_id' => $quote->id,
                    'bitrix_id' => $dealId,
                    'last_payload_hash' => hash('sha256', (string) json_encode($dealPayload)),
                    'last_correlation_id' => $this->correlationId,
                    'last_pushed_at' => now(),
                    'created_via' => BitrixEntityMap::VIA_PUSH,
                ]);
            } else {
                // Re-approval — dealUpdate same Deal (idempotent QUOT-07).
                $dealId = $map->bitrix_id;
                $client->dealUpdate($dealId, $dealPayload, $this->correlationId);
                $map->update([
                    'last_payload_hash' => hash('sha256', (string) json_encode($dealPayload)),
                    'last_correlation_id' => $this->correlationId,
                    'last_pushed_at' => now(),
                ]);
            }

            // ── Step 4: Replace product rows (idempotent — full replace) ────
            $rows = $this->buildLineRows($quote, $calc);
            $client->dealProductRowsSet((int) $dealId, $rows, $this->correlationId);

            Log::info('PushQuoteToBitrixDealJob: pushed quote', [
                'quote_id' => $quote->id,
                'bitrix_deal_id' => $dealId,
                'correlation_id' => $this->correlationId,
                'mode' => $map === null ? 'created' : 'updated',
            ]);
        } catch (BitrixPermanentException $e) {
            // 4xx / auth — fail fast, no retries.
            $this->emitFailedSuggestion('permanent_validation', $quote->id, $e->getMessage());
            $this->fail($e);
        }
        // BitrixTransientException propagates to Laravel's retry machinery.
    }

    public function failed(Throwable $e): void
    {
        // Don't double-write a suggestion for fail-fast 4xx (already emitted in handle()).
        if (! $e instanceof BitrixPermanentException) {
            $this->emitFailedSuggestion('push_exhausted', $this->quoteId, $e->getMessage());
        }

        $this->notifyQuoteAlerts($e);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDealPayload(Quote $quote, string $contactId): array
    {
        $totalIncVat = (int) $quote->total_pence_at_quote;
        $totalDecimal = number_format($totalIncVat / 100, 2, '.', '');

        return [
            'TITLE' => sprintf('Quote %s for %s', $quote->ulidShort(), $quote->customer_email),
            'TYPE_ID' => (string) config('quote.bitrix_deal_type_id', 'QUOTE'),
            'OPPORTUNITY' => $totalDecimal,
            'CURRENCY_ID' => 'GBP',
            'CONTACT_ID' => $contactId,
            'UF_CRM_WOO_QUOTE_ID' => $quote->id,
        ];
    }

    /**
     * Build the line-item rows for crm.deal.productrows.set.
     *
     * Row shape per RESEARCH §11 verified vendor SDK:
     *   PRODUCT_NAME / PRICE / PRICE_EXCLUSIVE / PRICE_NETTO / PRICE_BRUTTO /
     *   QUANTITY / TAX_RATE / TAX_INCLUDED='Y' / CUSTOMIZED='Y' /
     *   MEASURE_CODE=796 / MEASURE_NAME='pcs' / SORT
     *
     * PriceCalculator is INJECTED (not facade-accessed) — cleaner for testing
     * and respects RESEARCH W3 fix (PriceSnapshotter v1.0 contract).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLineRows(Quote $quote, PriceCalculator $calc): array
    {
        $rows = [];
        foreach ($quote->lines as $i => $line) {
            $unitIncVat = (int) $line->unit_price_pence_at_quote;
            $unitExVat = $calc->stripVat($unitIncVat);
            $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
            $name = (string) ($snapshot['name'] ?? $line->sku);

            $rows[] = [
                'PRODUCT_ID' => 0,
                'PRODUCT_NAME' => $name,
                'PRICE' => number_format($unitIncVat / 100, 2, '.', ''),
                'PRICE_EXCLUSIVE' => number_format($unitExVat / 100, 2, '.', ''),
                'PRICE_NETTO' => number_format($unitExVat / 100, 2, '.', ''),
                'PRICE_BRUTTO' => number_format($unitIncVat / 100, 2, '.', ''),
                'QUANTITY' => (string) (int) $line->quantity_int,
                'TAX_RATE' => '20',
                'TAX_INCLUDED' => 'Y',
                'CUSTOMIZED' => 'Y',
                'MEASURE_CODE' => 796,
                'MEASURE_NAME' => 'pcs',
                'SORT' => ($i + 1) * 10,
            ];
        }

        return $rows;
    }

    private function emitFailedSuggestion(string $subKind, string $quoteId, string $errorMessage): void
    {
        Suggestion::create([
            'kind' => 'quote_push_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => $this->correlationId,
            'payload' => [
                'sub_kind' => $subKind,
                'quote_id' => $quoteId,
                'error_message' => $errorMessage,
            ],
            'evidence' => [
                'correlation_id' => $this->correlationId,
                'quote_id' => $quoteId,
                'retry_count' => $this->attempts(),
            ],
            'proposed_at' => now(),
        ]);
    }

    private function notifyQuoteAlerts(Throwable $e): void
    {
        // Phase 1 D-13 / Phase 4 inherited — 5-min Cache::add dedup per quote.
        $lockKey = 'quote-push-failed-alert:'.$this->quoteId;
        if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
            return;
        }

        try {
            $quote = Quote::find($this->quoteId);
            $ulidShort = $quote?->ulidShort() ?? substr($this->quoteId, 0, 8);

            Notification::send(
                new AlertDistribution(onlyReceiving: 'receives_quote_alerts'),
                new QuotePushFailedNotification(
                    quoteId: $this->quoteId,
                    quoteUlidShort: $ulidShort,
                    errorMessage: $e->getMessage(),
                    correlationId: $this->correlationId !== '' ? $this->correlationId : null,
                ),
            );
        } catch (Throwable $notifyEx) {
            // Never let a notification failure mask the original exception.
            Log::error('PushQuoteToBitrixDealJob::notifyQuoteAlerts failed', [
                'quote_id' => $this->quoteId,
                'error' => $notifyEx->getMessage(),
            ]);
        }
    }
}
