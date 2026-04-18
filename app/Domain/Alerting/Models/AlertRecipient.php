<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Email recipient for failed-job alerts (D-12).
 *
 * Managed exclusively via Filament AlertRecipientResource (admin-only).
 * AlertDistribution Notifiable resolves to `where('is_active', true)` at
 * dispatch time — toggling is_active takes effect immediately, no cache.
 *
 * Pitfall M: AlertRecipientSeeder ships ops@meetingstore.co.uk as the
 * fallback row so an empty table never causes silent alerting outage.
 */
class AlertRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'name',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
