<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Email recipient for failed-job alerts (D-12) + sync-report distribution (D-08).
 *
 * Managed exclusively via Filament AlertRecipientResource (admin-only).
 * AlertDistribution Notifiable resolves to `where('is_active', true)` at
 * dispatch time — toggling is_active takes effect immediately, no cache.
 *
 * Pitfall M: AlertRecipientSeeder ships ops@meetingstore.co.uk as the
 * fallback row so an empty table never causes silent alerting outage.
 *
 * Plan 02-04 D-08: receives_sync_reports is opt-in for the daily supplier
 * sync CSV report. Default TRUE so the seeded fallback starts receiving
 * reports without manual intervention.
 */
class AlertRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'name',
        'is_active',
        'notes',
        'receives_sync_reports',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'receives_sync_reports' => 'boolean',
    ];

    /** Scope: only rows with is_active=true. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Scope: only rows opted-in to the sync report (D-08). */
    public function scopeReceivesSyncReports(Builder $q): Builder
    {
        return $q->where('receives_sync_reports', true);
    }
}
