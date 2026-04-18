<?php

declare(strict_types=1);

namespace App\Foundation\Events;

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
 */
abstract class DomainEvent
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
