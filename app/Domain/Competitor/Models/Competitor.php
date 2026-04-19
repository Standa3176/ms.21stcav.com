<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 5 Plan 01 — Competitor master record (COMP-01..COMP-12 anchor).
 *
 * Ops-managed via Filament (Plan 05-04). Seeded empty per D-02 — first
 * sighting of a new CSV filename prefix auto-creates a `status=pending`
 * row via the watcher (D-01) so ops can promote it to active after
 * visual inspection in the Ingest Issues page.
 *
 * LogsActivity ON — schema changes at this layer are audit-worthy
 * (competitor rename / website change / status flip are rare but
 * forensically important when a price-history query surprises ops).
 */
final class Competitor extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'slug',
        'name',
        'website_url',
        'map_policy_notes',
        'status',
        'is_active',
        'last_ingest_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_ingest_at' => 'datetime',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(CompetitorPrice::class);
    }

    public function ingestRuns(): HasMany
    {
        return $this->hasMany(CompetitorIngestRun::class);
    }

    public function csvMapping(): HasOne
    {
        return $this->hasOne(CompetitorCsvMapping::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)->where('is_active', true);
    }

    /** "Is this competitor both status=active and is_active=true?" */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_active === true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'name', 'website_url', 'status', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorFactory
    {
        return CompetitorFactory::new();
    }
}
