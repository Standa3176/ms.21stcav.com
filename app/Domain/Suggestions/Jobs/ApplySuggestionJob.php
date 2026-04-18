<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Jobs;

use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Dispatched by SuggestionResource::approve action. Resolves the applier per kind,
 * runs apply(), writes integration_events, updates status → 'applied'.
 *
 * Idempotency guard (D-15): if status is already 'applied', return immediately.
 * This means retries from Horizon after a transient failure never double-apply.
 */
final class ApplySuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // NOTE: $queue is NOT redeclared here — Queueable trait supplies it (public ?string $queue).
    // Redeclaring with a different signature (e.g. non-nullable string) triggers a PHP 8.4
    // trait property conflict fatal. Callers override at dispatch-time with onQueue('default').
    public int $tries = 3;

    /** @var array<int, int> backoff in seconds */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $suggestionId)
    {
        $this->onQueue('default');
    }

    public function handle(SuggestionApplierResolver $resolver, IntegrationLogger $logger): void
    {
        $suggestion = Suggestion::findOrFail($this->suggestionId);

        // D-15 idempotency guard — short-circuit if already applied.
        if ($suggestion->status === Suggestion::STATUS_APPLIED) {
            return;
        }

        // Restore correlation_id into Context so IntegrationLogger picks it up without
        // explicit threading (belt-and-braces — Laravel 12 Context::hydrated should
        // already handle this on the queue side, but in tests we may call handle() directly).
        Context::add('correlation_id', $suggestion->correlation_id);

        try {
            $applier = $resolver->resolve($suggestion);
            $result = $applier->apply($suggestion);

            $suggestion->update([
                'status' => Suggestion::STATUS_APPLIED,
                'applied_at' => now(),
            ]);

            $logger->log([
                'channel' => 'suggestions',
                'operation' => "apply:{$suggestion->kind}",
                'endpoint' => 'internal',
                'method' => 'APPLY',
                'request_body' => $suggestion->payload,
                'response_body' => $result,
                'http_status' => 200,
                'status' => 'success',
                'subject_type' => Suggestion::class,
                // ULID — integration_events.subject_id is nullableUlidMorphs (CHAR(26))
                // per Plan 03 migration; no cast needed.
                'subject_id' => $suggestion->id,
            ]);
        } catch (\Throwable $e) {
            $suggestion->update([
                'status' => Suggestion::STATUS_FAILED,
                'rejection_reason' => $e->getMessage(),
            ]);

            $logger->log([
                'channel' => 'suggestions',
                'operation' => "apply:{$suggestion->kind}",
                'endpoint' => 'internal',
                'method' => 'APPLY',
                'request_body' => $suggestion->payload,
                'response_body' => ['error' => $e->getMessage()],
                'http_status' => 500,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
