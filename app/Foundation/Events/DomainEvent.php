<?php

declare(strict_types=1);

namespace App\Foundation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Every module-level domain event extends this.
 *
 * Auto-populates correlation_id from Laravel 12 Context facade — survives the HTTP→queue
 * boundary via automatic dehydrate/hydrate (no manual payload stuffing required).
 *
 * FOUND-03 compliance: every event a downstream listener sees carries a correlation_id
 * threaded through audit_log + integration_events + suggestions for the full chain.
 *
 * Subclass convention (T-03-05 mitigation): events MUST carry primitive fields (SKUs, IDs, strings),
 * NEVER full Eloquent models — SerializesModels leaks hidden columns on dispatch otherwise.
 *
 * Pitfall P2-I (Phase 2 Plan 03 retrofit): implements ShouldDispatchAfterCommit so
 * events dispatched inside a DB::transaction() that rolls back do NOT fire listeners.
 * Critical for SyncChunkJob which wraps per-SKU writes in transactions — a rolled-back
 * Woo write MUST NOT trigger Phase 3's price-recompute listener. OUTSIDE transactions
 * the semantics are unchanged (immediate dispatch).
 */
abstract class DomainEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly string $correlationId;

    public readonly string $occurredAt;

    public function __construct()
    {
        $this->correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $this->occurredAt = now()->toIso8601String();
    }
}
