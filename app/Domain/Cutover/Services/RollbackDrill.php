<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7 Plan 05 Task 2 — CUT-05 rollback drill.
 *
 * Runs the 5-step rollback playbook in dry-run (simulation) or --live mode.
 * LIVE mode is env-gated by CUTOVER_DRILL_ALLOWED=true — the command wrapper
 * fails fast when the env var isn't set, and even then the drill is
 * semantically STAGING-ONLY (ops sets the env var in staging, not prod).
 *
 * The 5 steps (D-16):
 *   1. flag readable        — WOO_WRITE_ENABLED env var is set (so the
 *                              operator can flip it back to false).
 *   2. flag flip simulated  — log-only in both modes (the real .env edit is
 *                              out-of-band; this step documents the exact
 *                              command ops must run).
 *   3. backup verifiable    — confirm a recent woo-db-backup-*.sql.gz exists
 *                              in config('cutover.backup_path').
 *   4. legacy cron check    — MANUAL (ops verifies WP admin → Tools →
 *                              Scheduled Actions OR `wp cron event list`).
 *   5. drill report written — emit the markdown report to
 *                              config('cutover.drill_report_path').
 *
 * Every run writes an audit_log entry (live_run or dry_run) with the per-
 * step PASS/FAIL/WARN/MANUAL summary so ops can reconcile against the
 * drill-report markdown.
 */
class RollbackDrill
{
    public function __construct(protected Auditor $auditor) {}

    /**
     * @return array<string, mixed>  Per-step status + report_path
     */
    public function run(bool $live = false): array
    {
        $results = [];
        $reportLines = [
            '# Rollback Drill Report — '.now()->toIso8601String(),
            '',
            'Mode: '.($live ? 'LIVE' : 'DRY-RUN'),
            '',
        ];

        // ── STEP 1 — flag readable ────────────────────────────────────────
        // Read via config() (not env()) — env() outside config/*.php returns
        // the .env default at config:cache build time (see d7d0e39 +
        // 2026-05-31 cutover incident). The binding lives in
        // config('cutover.woo_write_enabled').
        $flagValue = config('cutover.woo_write_enabled');
        $flagReadable = $flagValue !== null;
        $results['step_1_flag_readable'] = $flagReadable ? 'PASS' : 'FAIL';
        $reportLines[] = sprintf(
            'STEP 1/5: flag readable — %s (current WOO_WRITE_ENABLED=%s)',
            $results['step_1_flag_readable'],
            var_export($flagValue, true),
        );

        // ── STEP 2 — flag flip simulation ─────────────────────────────────
        $results['step_2_flag_flip_simulated'] = 'PASS';
        $reportLines[] = 'STEP 2/5: flag flip simulated — PASS '
            .'(ops must set WOO_WRITE_ENABLED=false in .env + run `php artisan config:clear`)';

        // ── STEP 3 — backup verifiable ────────────────────────────────────
        $backupPath = (string) config('cutover.backup_path');
        $backups = is_dir($backupPath)
            ? (glob($backupPath.'/woo-db-backup-*.sql.gz') ?: [])
            : [];
        $latestBackup = $backups !== [] ? end($backups) : null;
        $results['step_3_backup_verifiable'] = $latestBackup !== null ? 'PASS' : 'WARN';
        $reportLines[] = sprintf(
            'STEP 3/5: backup verifiable — %s (latest: %s)',
            $results['step_3_backup_verifiable'],
            $latestBackup ?? 'none found — run `php artisan cutover:snapshot-woo-db --label=drill`',
        );

        // ── STEP 4 — legacy cron re-engage check ─────────────────────────
        $results['step_4_legacy_cron_check'] = 'MANUAL';
        $reportLines[] = 'STEP 4/5: legacy-plugin cron re-engage — MANUAL '
            .'(ops verifies WP admin → Tools → Scheduled Actions OR `wp cron event list`)';

        // ── STEP 5 — drill report written ─────────────────────────────────
        $reportDir = (string) config('cutover.drill_report_path');
        if (! is_dir($reportDir)) {
            mkdir($reportDir, 0o755, true);
        }
        $reportPath = $reportDir.DIRECTORY_SEPARATOR.'drill-report-'.now()->format('Y-m-d').'.md';
        $reportLines[] = '';
        $reportLines[] = 'STEP 5/5: drill report written to '.$reportPath;

        file_put_contents($reportPath, implode("\n", $reportLines));
        $results['step_5_drill_report'] = 'PASS';
        $results['report_path'] = $reportPath;

        $this->auditor->record(
            'cutover.rollback_drill_'.($live ? 'live' : 'dry_run'),
            $results,
        );

        if (! $flagReadable) {
            Log::warning('RollbackDrill: WOO_WRITE_ENABLED env var is unset — step 1 failed.');
        }

        return $results;
    }
}
