<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

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
 *
 * Plan 04-03 D-12: receives_crm_alerts is opt-in for CRM push-failed DLQ
 * alerts. Default FALSE (evidence payloads may carry order PII) but the
 * seeded fallback row is force-updated to TRUE by the migration so the
 * Pitfall M "no active recipient" outage can't strand CRM alerts.
 *
 * Plan 05-01: receives_competitor_alerts is opt-in for competitor stale-feed
 * + CSV-issue alerts (Phase 2 D-08 + Phase 4 D-12 pattern). Default FALSE;
 * seeded fallback force-updated TRUE so alerts always land somewhere.
 */
class AlertRecipient extends Model
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'email',
        'name',
        'is_active',
        'notes',
        'receives_sync_reports',
        'receives_crm_alerts',
        'receives_competitor_alerts',
        'receives_auto_create_alerts',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'receives_sync_reports' => 'boolean',
        'receives_crm_alerts' => 'boolean',
        'receives_competitor_alerts' => 'boolean',
        'receives_auto_create_alerts' => 'boolean',
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

    /** Scope: only rows opted-in to CRM push-failed alerts (Plan 04-03 D-12). */
    public function scopeReceivesCrmAlerts(Builder $q): Builder
    {
        return $q->where('receives_crm_alerts', true);
    }

    /** Scope: only rows opted-in to competitor stale-feed / CSV-issue alerts (Plan 05-01). */
    public function scopeReceivesCompetitorAlerts(Builder $q): Builder
    {
        return $q->where('receives_competitor_alerts', true);
    }

    /** Scope: only rows opted-in to auto-create-failed alerts (Phase 6 Plan 01). */
    public function scopeReceivesAutoCreateAlerts(Builder $q): Builder
    {
        return $q->where('receives_auto_create_alerts', true);
    }
}
