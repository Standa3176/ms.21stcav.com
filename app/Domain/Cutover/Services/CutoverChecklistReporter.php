<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Domain\Dashboard\Models\DashboardSnapshot;

/**
 * Phase 7 Plan 05 Task 3 — CUT-05 readiness + D-21 checklist reporter.
 *
 * Single source of truth for "is it safe to flip WOO_WRITE_ENABLED=true?"
 * Integrates:
 *   - Phase 6 D-20 carry-forward gates (3):
 *       1. supplier-probe     — live supplier API probe re-run
 *       2. woo-sandbox        — Woo URL-pass-through sandbox validation
 *       3. feature-suite      — Full-tier Feature Pest suite run
 *   - D-19 cutover runbook steps (10+):
 *       woo-db-snapshot, divergence-scan, parity-threshold,
 *       populate-overrides, drill-rollback-staging, legacy-plugins-disabled,
 *       flag-flip, monitoring-7-days, weekly-digest-landed, handover-docs
 *
 * Gate statuses:
 *   PASS    — automated check succeeded OR ops set --update-status=id:pass
 *   PENDING — not yet done
 *   FAIL    — automated check failed (e.g. parity below threshold)
 *   MANUAL  — only a human can verify (e.g. Woo sandbox validation)
 *
 * Ops set statuses for the manual items via:
 *   php artisan cutover:checklist --update-status=item-id:pass
 *
 * Status overrides are persisted to storage/app/cutover/checklist-state.json
 * so a subsequent checklist invocation reflects the override.
 *
 * The command exits with code 1 if ANY item is PENDING or FAIL, enabling
 * CI/CD wiring: a green `cutover:checklist` is the go/no-go signal.
 */
class CutoverChecklistReporter
{
    public const STATUS_PASS = 'PASS';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_FAIL = 'FAIL';
    public const STATUS_MANUAL = 'MANUAL';

    protected string $stateFile;

    public function __construct()
    {
        $this->stateFile = storage_path('app/cutover/checklist-state.json');
    }

    /**
     * @return array<int, array{id:string, title:string, status:string, action:string}>
     */
    public function gates(): array
    {
        $state = $this->loadState();

        return [
            // ── Phase 6 D-20 carry-forward gates (3) ──────────────────────
            [
                'id' => 'supplier-probe',
                'title' => 'D-20 Gate 1: Supplier API probe re-run with live 21stcav.com creds',
                'status' => $state['supplier-probe'] ?? $this->checkSupplierProbe(),
                'action' => 'Run: php artisan supplier:probe-single-sku <LIVE-SKU>; verify '
                    .'storage/app/research/supplier-probe.json has no __synthesized=true marker',
            ],
            [
                'id' => 'woo-sandbox',
                'title' => 'D-20 Gate 2: Woo URL-pass-through sandbox validation',
                'status' => $state['woo-sandbox'] ?? self::STATUS_PENDING,
                'action' => 'Manual POST /wp-json/wc/v3/products with images[] against Woo sandbox; '
                    .'confirm images[0].src points to /wp-content/uploads/…; '
                    .'then cutover:checklist --update-status=woo-sandbox:pass',
            ],
            [
                'id' => 'feature-suite',
                'title' => 'D-20 Gate 3: Full-suite Feature-tier Pest run against meetingstore_ops_testing MySQL',
                'status' => $state['feature-suite'] ?? $this->checkFeatureSuite(),
                'action' => 'Run: vendor/bin/pest --compact; '
                    .'then cutover:checklist --update-status=feature-suite:pass',
            ],
            // ── D-19 cutover runbook sequence (10+) ───────────────────────
            [
                'id' => 'woo-db-snapshot',
                'title' => 'CUT-04: Woo DB snapshot taken',
                'status' => $state['woo-db-snapshot'] ?? $this->checkWooDbSnapshot(),
                'action' => 'Run: php artisan cutover:snapshot-woo-db --label=pre-cutover',
            ],
            [
                'id' => 'divergence-scan',
                'title' => 'CUT-01: Divergence scan ran + persisted latest result',
                'status' => $state['divergence-scan'] ?? $this->checkDivergenceScanRun(),
                'action' => 'Run: php artisan cutover:divergence-scan --live',
            ],
            [
                'id' => 'parity-threshold',
                'title' => 'CUT-01 parity ≥ '.(int) config('cutover.parity_threshold_percent', 99)
                    .'% over '.(int) config('cutover.parity_window_days', 7).'-day window',
                'status' => $state['parity-threshold'] ?? $this->checkParityThreshold(),
                'action' => 'Visit /admin — SyncDiffsParityWidget green traffic light; '
                    .'or query dashboard_snapshots.sync_diffs_parity.parity_percent',
            ],
            [
                'id' => 'populate-overrides',
                'title' => 'CUT-02: ProductOverride rows populated from divergence scan',
                'status' => $state['populate-overrides'] ?? self::STATUS_PENDING,
                'action' => 'Run: php artisan cutover:populate-overrides --live; '
                    .'then cutover:checklist --update-status=populate-overrides:pass',
            ],
            [
                'id' => 'drill-rollback-staging',
                'title' => 'CUT-05: Rollback drill executed on staging clone',
                'status' => $state['drill-rollback-staging'] ?? self::STATUS_PENDING,
                'action' => 'Run on STAGING: CUTOVER_DRILL_ALLOWED=true php artisan cutover:drill-rollback --live; '
                    .'then cutover:checklist --update-status=drill-rollback-staging:pass',
            ],
            [
                'id' => 'legacy-plugins-disabled',
                'title' => 'CUT-03 + CUT-07: Legacy plugin crons deregistered + plugins deactivated',
                'status' => $state['legacy-plugins-disabled'] ?? self::STATUS_PENDING,
                'action' => 'Run: CUTOVER_DISABLE_LIVE_ALLOWED=true php artisan cutover:disable-legacy-plugins --live; '
                    .'then cutover:checklist --update-status=legacy-plugins-disabled:pass',
            ],
            [
                'id' => 'flag-flip',
                'title' => 'WOO_WRITE_ENABLED=true flipped in production .env + config:clear',
                'status' => $state['flag-flip'] ?? self::STATUS_PENDING,
                'action' => 'Ops changes production .env: WOO_WRITE_ENABLED=true; '
                    .'then php artisan config:clear; '
                    .'then cutover:checklist --update-status=flag-flip:pass',
            ],
            [
                'id' => 'monitoring-7-days',
                'title' => 'CUT-07: 7 consecutive days without divergence alarms',
                'status' => $state['monitoring-7-days'] ?? self::STATUS_PENDING,
                'action' => 'Monitor Home Dashboard daily for 7 days post-flag-flip; '
                    .'then cutover:checklist --update-status=monitoring-7-days:pass',
            ],
            [
                'id' => 'weekly-digest-landed',
                'title' => 'DASH-05: First weekly digest email landed in admin distribution list',
                'status' => $state['weekly-digest-landed'] ?? $this->checkWeeklyDigestLanded(),
                'action' => 'Wait for next Monday 07:00 London; '
                    .'check dashboard_snapshots.weekly_report_status.last_sent_at',
            ],
            [
                'id' => 'handover-docs',
                'title' => 'CUT-06: Ops handover docs committed to docs/ops/cutover-handover.md',
                'status' => $state['handover-docs'] ?? $this->checkHandoverDocs(),
                'action' => 'See Plan 07-06; '
                    .'then cutover:checklist --update-status=handover-docs:pass',
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Automated gate checks
    // ══════════════════════════════════════════════════════════════════════

    protected function checkSupplierProbe(): string
    {
        $path = storage_path('app/research/supplier-probe.json');
        if (! file_exists($path)) {
            return self::STATUS_PENDING;
        }
        $contents = (string) file_get_contents($path);

        // Phase 6 Plan 01's probe marks synthesised fixtures with __synthesized=true;
        // a real live-creds probe has no such marker.
        if (str_contains($contents, '"__synthesized": true')
            || str_contains($contents, '"__synthesized":true')) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_PASS;
    }

    protected function checkFeatureSuite(): string
    {
        $snap = DashboardSnapshot::where('metric_key', 'feature_suite_last_run')->first();
        if ($snap === null) {
            return self::STATUS_PENDING;
        }
        $payload = $snap->metric_value_json ?? [];
        $status = $payload['status'] ?? null;

        return match ($status) {
            'pass' => self::STATUS_PASS,
            'fail' => self::STATUS_FAIL,
            default => self::STATUS_PENDING,
        };
    }

    protected function checkWooDbSnapshot(): string
    {
        $backupDir = (string) config('cutover.backup_path');
        if (! is_dir($backupDir)) {
            return self::STATUS_PENDING;
        }
        $backups = glob($backupDir.'/woo-db-backup-*.sql.gz') ?: [];

        return $backups !== [] ? self::STATUS_PASS : self::STATUS_PENDING;
    }

    protected function checkDivergenceScanRun(): string
    {
        $row = DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->first();

        return $row !== null ? self::STATUS_PASS : self::STATUS_PENDING;
    }

    protected function checkParityThreshold(): string
    {
        $row = DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->first();
        if ($row === null) {
            return self::STATUS_PENDING;
        }
        $payload = $row->metric_value_json ?? [];
        $parity = $payload['parity_percent'] ?? null;
        $threshold = (int) config('cutover.parity_threshold_percent', 99);

        if ($parity === null) {
            return self::STATUS_PENDING;
        }

        return $parity >= $threshold ? self::STATUS_PASS : self::STATUS_FAIL;
    }

    protected function checkWeeklyDigestLanded(): string
    {
        $row = DashboardSnapshot::where('metric_key', 'weekly_report_status')->first();
        if ($row === null) {
            return self::STATUS_PENDING;
        }
        $payload = $row->metric_value_json ?? [];
        $lastSent = $payload['last_sent_at'] ?? null;

        return $lastSent ? self::STATUS_PASS : self::STATUS_PENDING;
    }

    protected function checkHandoverDocs(): string
    {
        $path = base_path('docs/ops/cutover-handover.md');

        return file_exists($path) ? self::STATUS_PASS : self::STATUS_PENDING;
    }

    // ══════════════════════════════════════════════════════════════════════
    // State persistence (--update-status sub-command writes here)
    // ══════════════════════════════════════════════════════════════════════

    public function updateStatus(string $id, string $status): void
    {
        $state = $this->loadState();
        $state[$id] = match (strtolower($status)) {
            'pass' => self::STATUS_PASS,
            'fail' => self::STATUS_FAIL,
            'pending' => self::STATUS_PENDING,
            'manual' => self::STATUS_MANUAL,
            default => throw new \InvalidArgumentException("Unknown status: {$status}"),
        };

        $dir = dirname($this->stateFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    /** @return array<string, string> */
    protected function loadState(): array
    {
        if (! file_exists($this->stateFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->stateFile), true);

        return is_array($decoded) ? $decoded : [];
    }
}
