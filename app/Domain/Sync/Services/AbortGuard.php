<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Models\SyncRun;

/**
 * D-06 tiered abort trigger — STATELESS service with DB-backed counters.
 *
 * Each method issues atomic SQL UPDATE / SELECT on the sync_runs row so multiple
 * worker processes on sync-woo-push-supervisor share state. The previous
 * in-memory design produced false-negatives under multi-worker supervisors
 * (Checker blocker): 50 consecutive failures split 17/17/16 across 3 workers
 * would never trip the threshold.
 *
 * IMPORTANT — do NOT bind as a singleton. Fresh instances per resolve are
 * correct because state lives on the sync_runs row, not on the instance.
 *
 * IMPORTANT — AbortGuard owns the failed_count + consecutive_failures + total_skus
 * DB columns. SyncRun::incrementCounter() is used for the DISJOINT columns
 * (updated_count, skipped_count, missing_count, unknown_sku_count). Callers
 * MUST NOT call $run->incrementCounter('failed') AND $guard->recordFailure()
 * on the same failure — double-counting.
 *
 * Performance: ~3 DB writes per SKU + 1 SELECT on throwIfTriggered → ~4.5k + 1.5k
 * queries per 15k-SKU run. Acceptable on local MySQL (sub-ms per row). If profiling
 * flags this as hot, switch to Redis cache with 5s TTL — not required for v1.
 */
final class AbortGuard
{
    public const ERROR_RATE_THRESHOLD = 0.20;         // D-06(a)
    public const ERROR_RATE_MIN_SAMPLES = 500;
    public const CONSECUTIVE_FAILURE_THRESHOLD = 50;  // D-06(b)

    /**
     * Successful SKU — bumps total_skus and resets the consecutive counter.
     * Two atomic UPDATEs; reset happens first so a concurrent reader during
     * the small window sees the lower (safer) value.
     */
    public function recordSuccess(int $runId): void
    {
        SyncRun::where('id', $runId)->update(['consecutive_failures' => 0]);
        SyncRun::where('id', $runId)->increment('total_skus');
    }

    /**
     * Failed SKU — bumps total_skus, failed_count, consecutive_failures atomically.
     * Each ->increment() is a single `UPDATE ... SET col = col + 1 WHERE id = ?` so
     * concurrent workers cannot race.
     */
    public function recordFailure(int $runId): void
    {
        SyncRun::where('id', $runId)->increment('total_skus');
        SyncRun::where('id', $runId)->increment('failed_count');
        SyncRun::where('id', $runId)->increment('consecutive_failures');
    }

    /**
     * D-06(c) — flag the run as JWT-broken on the DB. Caller typically follows
     * this with throwIfTriggered() to surface the SyncAbortException.
     *
     * We stash the reason onto abort_reason (reusing the existing enum) so even
     * if the orchestrator crashes before catching SyncAbortException, operators
     * can see why on the sync_runs row.
     */
    public function triggerJwtFailure(int $runId): void
    {
        SyncRun::where('id', $runId)->update(['abort_reason' => SyncRun::ABORT_JWT_REFRESH]);
    }

    /**
     * Read shared DB state and throw SyncAbortException if any D-06 trigger fires.
     * JWT failure is checked first so a flagged run aborts immediately regardless
     * of counter values.
     *
     * @throws SyncAbortException
     */
    public function throwIfTriggered(int $runId): void
    {
        /** @var SyncRun $run */
        $run = SyncRun::query()
            ->select(['id', 'failed_count', 'total_skus', 'consecutive_failures', 'abort_reason'])
            ->findOrFail($runId);

        if ($run->abort_reason === SyncRun::ABORT_JWT_REFRESH) {
            throw new SyncAbortException(
                SyncRun::ABORT_JWT_REFRESH,
                'JWT refresh failed (D-06c).',
            );
        }

        if ($run->consecutive_failures >= self::CONSECUTIVE_FAILURE_THRESHOLD) {
            throw new SyncAbortException(
                SyncRun::ABORT_CONSECUTIVE,
                "{$run->consecutive_failures} consecutive failures (D-06b).",
            );
        }

        if ($run->total_skus >= self::ERROR_RATE_MIN_SAMPLES
            && ($run->failed_count / max(1, $run->total_skus)) > self::ERROR_RATE_THRESHOLD) {
            $rate = number_format(($run->failed_count / $run->total_skus) * 100, 1);
            throw new SyncAbortException(
                SyncRun::ABORT_ERROR_RATE,
                "Error rate {$rate}% exceeded 20% after {$run->total_skus} SKUs (D-06a).",
            );
        }
    }
}
