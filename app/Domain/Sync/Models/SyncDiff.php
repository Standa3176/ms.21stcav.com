<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shadow-mode write destination. When services.woo.write_enabled=false (the default),
 * WooClient writes records here instead of calling Woo. Phase 7 cutover flips the
 * flag to true and a separate replay command reads pending diffs + sends them to Woo.
 *
 * Append-only; updated_at not tracked ($timestamps=false). status flips from
 * 'pending' → 'applied' | 'superseded' on replay.
 */
class SyncDiff extends Model
{
    public $timestamps = false; // created_at set explicitly; no updated_at

    protected $fillable = [
        'channel',
        'method',
        'endpoint',
        'woo_id',
        'payload',
        'correlation_id',
        'created_at',
        'applied_at',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'applied_at' => 'datetime',
    ];
}
