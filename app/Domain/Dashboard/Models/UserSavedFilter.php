<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Models;

use App\Models\User;
use Database\Factories\Domain\Dashboard\UserSavedFilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 Plan 01 — D-07 per-user saved Filament table filter.
 *
 * Plan 07-03 extends every Filament Resource's table with a "Save current
 * filter" button + a dropdown to apply saved filters. This model is the
 * backing store — one row per (user, Resource slug, filter name).
 *
 * Threat model:
 *   - T-07-01-01 (cross-user read): UserSavedFilterPolicy::view checks
 *     ownership; Plan 07-03 Resource queries scope by auth()->id().
 *   - T-07-01-02 (JSON payload tampering): filter_payload_json is
 *     untrusted. Plan 07-03 validates the payload against the Resource's
 *     declared filter schema before applying it (defence-in-depth; Filament
 *     itself is the primary guard since it re-hydrates its own filter
 *     state through its own type-checked accessors).
 *
 * Cascade: FK on user_id cascades delete — when a user is deleted, their
 * saved filters go with them. No soft-deletes (filter state is ephemeral).
 */
final class UserSavedFilter extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_slug',
        'filter_name',
        'filter_payload_json',
    ];

    protected $casts = [
        'filter_payload_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): UserSavedFilterFactory
    {
        return UserSavedFilterFactory::new();
    }
}
