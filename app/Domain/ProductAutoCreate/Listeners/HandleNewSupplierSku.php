<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 Plan 03 — AUTO-01 primary listener.
 *
 * Replaces Phase 2's StubNewSupplierSkuListener. Receives NewSupplierSkuDetected
 * (fired by SyncSupplierCommand when a supplier row has no matching Woo SKU)
 * and either:
 *
 *   1. Short-circuits when ANY active AutoCreateSkipRule matches (D-04
 *      "auto_skipped" outcome logged to integration_events with the matched
 *      rule_ids so ops can audit).
 *   2. Dispatches CreateWooProductJob($event->sku) onto sync-woo-push.
 *
 * Queue: sync-bulk (reading skip rules + dispatching is cheap; keeps the
 * Woo-push queue reserved for the real Woo REST writes in CreateWooProductJob).
 *
 * Soft-failure semantics (T-06-03-04): a malformed rule regex (caught inside
 * AutoCreateSkipRule::matches() via @preg_match) returns false rather than
 * throwing, so a single bad rule can't crash the whole dispatch path. Any
 * unexpected exception during rule iteration is logged + swallowed — the job
 * still dispatches in that case (fail-open on auto-create dispatch is the
 * right default; worst case ops sees a spurious draft they can reject).
 */
final class HandleNewSupplierSku implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private IntegrationLogger $logger) {}

    /**
     * Queued-listener queue selector — listeners have no onQueue(); viaQueue() is
     * the sanctioned hook and avoids the PHP 8.4 public-$queue property collision
     * the class warns about (Phase 5 Plan 02 + Phase 6 Plan 02 lessons).
     */
    public function viaQueue(): string
    {
        return 'sync-bulk';
    }

    public function handle(NewSupplierSkuDetected $event): void
    {
        $matches = $this->collectMatchingSkipRules($event);

        if ($matches !== []) {
            $this->logger->log([
                'channel' => 'woo-auto-create',
                'direction' => 'outbound',
                'operation' => 'auto_skipped',
                'method' => 'APPLY',
                'endpoint' => 'internal://auto-create/skip',
                'request_body' => [
                    'sku' => $event->sku,
                    'supplier_price' => $event->supplierPrice,
                    'supplier_stock' => $event->supplierStock,
                    'matched_rule_ids' => $matches,
                ],
                'response_body' => ['skipped' => true],
                'http_status' => 0,
                'latency_ms' => 0,
                'status' => 'success',
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        CreateWooProductJob::dispatch($event->sku);
    }

    /**
     * Iterate active skip rules + collect the ids that match the event's SKU
     * + supplier price. Catches per-rule exceptions so one malformed row can't
     * take out the whole pipeline (T-06-03-04 DoS guard).
     *
     * @return array<int, int>
     */
    private function collectMatchingSkipRules(NewSupplierSkuDetected $event): array
    {
        $price = (float) $event->supplierPrice;
        $matched = [];

        try {
            $rules = AutoCreateSkipRule::query()->active()->get();
        } catch (\Throwable $e) {
            Log::warning('HandleNewSupplierSku: skip-rule query failed; fail-open', [
                'sku' => $event->sku,
                'error' => $e->getMessage(),
                'correlation_id' => $event->correlationId,
            ]);

            return [];
        }

        foreach ($rules as $rule) {
            try {
                if ($rule->matches($event->sku, $price)) {
                    $matched[] = (int) $rule->id;
                }
            } catch (\Throwable $e) {
                // Rule evaluation threw (catastrophic regex, etc.) — skip rule, keep going.
                Log::warning('HandleNewSupplierSku: rule evaluation error; rule skipped', [
                    'rule_id' => $rule->id,
                    'sku' => $event->sku,
                    'error' => $e->getMessage(),
                    'correlation_id' => $event->correlationId,
                ]);
            }
        }

        return $matched;
    }
}
