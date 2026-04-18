<?php

declare(strict_types=1);

namespace App\Foundation\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of every outbound/inbound integration call.
 *
 * Written exclusively by IntegrationLogger::log() — never via model ::create()
 * directly (the logger handles redaction + correlation_id auto-attach).
 *
 * Pruned by Plan 05 PruneIntegrationEventsCommand on a 90-day window (D-05).
 */
class IntegrationEvent extends Model
{
    public $timestamps = false; // append-only; created_at set explicitly

    protected $fillable = [
        'channel',
        'direction',
        'operation',
        'subject_type',
        'subject_id',
        'correlation_id',
        'endpoint',
        'method',
        'request_body',
        'request_headers',
        'response_body',
        'http_status',
        'latency_ms',
        'attempt',
        'status',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'request_body' => 'array',
        'request_headers' => 'array',
        'response_body' => 'array',
        'http_status' => 'integer',
        'latency_ms' => 'integer',
        'attempt' => 'integer',
        'created_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
