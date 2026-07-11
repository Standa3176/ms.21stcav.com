<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 15 Plan 15a-02 — GA4 channel/campaign daily snapshot row.
 *
 * One row per grain =
 *   date × channel_group (sessionDefaultChannelGroup)
 *        × source_medium (sessionSourceMedium)
 *        × campaign (sessionCampaignName, nullable).
 *
 * Written idempotently by `google:pull-ga4` (updateOrCreate on the grain).
 * READ-ONLY downstream — surfaced by the Marketing "GA4 Channels" Filament
 * viewer; the app never writes back to GA4/Google Ads.
 *
 * Money: purchase_revenue_pennies stores integer pennies (the command maps
 * the GA4 float revenue via (int) round($revenue * 100)). Lives in the
 * existing Integrations domain — no new Deptrac layer this slice.
 */
final class GaChannelMetric extends Model
{
    protected $table = 'ga_channel_metrics_daily';

    // No created_at/updated_at — pulled_at is the authoritative write timestamp
    // (matches the plan's explicit column grain; the table has no timestamps()).
    public $timestamps = false;

    protected $fillable = [
        'date',
        'channel_group',
        'source_medium',
        'campaign',
        'sessions',
        'key_events',
        'transactions',
        'purchase_revenue_pennies',
        'pulled_at',
    ];

    protected $casts = [
        'date' => 'date',
        'sessions' => 'integer',
        'key_events' => 'integer',
        'transactions' => 'integer',
        'purchase_revenue_pennies' => 'integer',
        'pulled_at' => 'datetime',
    ];
}
