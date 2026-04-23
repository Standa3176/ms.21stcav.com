<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Phase 6 Plan 03 — the core orchestrator for auto-create drafts.
 *
 * Task 1 ships the constructor + queue routing + failed() DLQ hook so the
 * HandleNewSupplierSku listener can dispatch against a real class (and
 * Queue::assertPushed() works). Task 2 adds the full handle() pipeline:
 * duplicate gate → supplier fetch → content compile → slug generate → pre-POST
 * Woo slug probe → taxonomy resolve → Product::create → pricing → Woo POST →
 * slug reconcile → image job chain → completeness score → AutoCreateSucceeded.
 *
 * Queue: sync-woo-push (Woo REST write — respects shared 429 backoff).
 * Retries: 3 with [30s, 5m, 30m] backoff (Phase 4 Pitfall P4-B precedent).
 * On exhaustion: failed() hook writes a kind='auto_create_failed' Suggestion
 * so the Plan 04 admin replay action has a row to act on (mirrors Phase 4
 * CrmPushRetryApplier DLQ pattern).
 */
final class CreateWooProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(
        public readonly string $sku,
        public readonly ?string $suggestionId = null,
    ) {
        // PHP 8.4 trait-collision guard — NEVER public string $queue (Phase 5 Plan 02 lesson).
        $this->onQueue('sync-woo-push');
    }

    public function handle(): void
    {
        // Task 2 supplies the full body. Task 1 ships this as a no-op placeholder
        // so HandleNewSupplierSku::dispatch() resolves the class (tests only assert
        // Queue::assertPushed — handle() never actually runs in those tests).
    }

    /**
     * Terminal-failure DLQ hook (Phase 1 D-17 / Phase 4 Plan 03 precedent).
     * Exhausted retries land in Suggestions so an admin can Replay via Plan 04.
     */
    public function failed(\Throwable $e): void
    {
        Suggestion::create([
            'kind' => 'auto_create_failed',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => Context::get('correlation_id'),
            'proposed_at' => now(),
            'evidence' => [
                'source' => 'CreateWooProductJob',
                'sku' => $this->sku,
                'original_suggestion_id' => $this->suggestionId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ],
        ]);
    }
}
