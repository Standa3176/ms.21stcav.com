<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Policies;

use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;

/**
 * Phase 15 Plan 15a-02 — read-only policy for the GA4 Channels viewer.
 *
 * GaChannelMetricResource is a READ-ONLY window over ga_channel_metrics_daily
 * (written only by the scheduled google:pull-ga4 pull). Consistent with the
 * other ambient read-only ops surfaces (Price History / dashboard snapshots):
 * any authenticated workspace user may view; ALL mutations are denied for
 * everyone (the table is producer-owned by the scheduled command).
 *
 * Pitfall P5-F — hand-written; DO NOT regenerate via `shield:generate`
 * (would emit Shield placeholder stub literals). PolicyTemplateIntegrityTest
 * scans this file on every CI run.
 */
final class GaChannelMetricPolicy
{
    public function viewAny(User $user): bool
    {
        // Authed workspace — any signed-in operator may view GA4 channel intel.
        return true;
    }

    public function view(User $user, GaChannelMetric $metric): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GaChannelMetric $metric): bool
    {
        return false;
    }

    public function delete(User $user, GaChannelMetric $metric): bool
    {
        return false;
    }

    public function restore(User $user, GaChannelMetric $metric): bool
    {
        return false;
    }

    public function forceDelete(User $user, GaChannelMetric $metric): bool
    {
        return false;
    }
}
