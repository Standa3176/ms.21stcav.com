<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260611-rl4 — cutover:auto-sync.
 *
 * Nightly 23:00 London chain that closes MS↔Woo drift overnight by orchestrating
 * three existing artisan commands in sequence:
 *
 *   Phase 1 SCAN    → cutover:divergence-scan --live
 *                       (writes sync_diffs + dashboard_snapshots.sync_diffs_parity)
 *   Phase 2 PUSH    → products:push-divergence-to-woo --field=… --no-confirm --limit=…
 *                       (consumes the sync_diffs and PUTs MS-side truth to Woo)
 *   Phase 3 RE-SCAN → cutover:divergence-scan --live
 *                       (writes a fresh parity_percent — different correlation_id
 *                        from phase 1's own; this measures the closed-loop result)
 *   Phase 4 REPORT  → counters table + audit log
 *                     + parity-regression detector
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DRIFT-PREVENTION CONTRACT
 *
 * All chained work flows through Artisan::call — see CHAINED_COMMANDS for
 * grep-discoverability. This command NEVER duplicates scan/push logic.
 *
 * If a chained command's signature changes, the Artisan::call exception
 * bubbles up loud — the fix is to update auto-sync's options dict here,
 * NOT to patch the dependent command.
 *
 * DivergenceScanner / PushDivergenceToWooCommand / DivergenceScanCommand
 * remain UNTOUCHED by this task.
 *
 * HydrateProductStockFromOffersCommand (260611-qcq) is NOT part of this
 * chain — it has its own Mon-Fri 07:20 cron.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * PARITY-REGRESSION ALARM
 *
 * After phase 3, compares parity_percent_after vs parity_percent_before.
 * If parity DECREASED (after < before) the run exits 1 (NOT 0) so cron logs
 * + Horizon visibly mark the run as failed and an audit log row
 * (`cutover.auto_sync_parity_regression`) is written. The auto-sync command
 * does NOT roll back pushed changes — this is a DETECTION alarm, NOT a
 * rollback mechanism. Operator investigates the next morning.
 *
 * Typical causes of regression:
 *   - Comparator predicate is wrong (silently flagging things as diverged
 *     that shouldn't be)
 *   - Woo cache hasn't invalidated (PUTs succeeded but reads return stale)
 *   - A different writer to Woo (legacy plugin re-emerged, manual edit
 *     during the run)
 *
 * USAGE
 *   php artisan cutover:auto-sync                                   (live)
 *   php artisan cutover:auto-sync --dry-run                         (plan)
 *   php artisan cutover:auto-sync --skip-scan                       (reuse latest sync_diffs)
 *   php artisan cutover:auto-sync --field=stock_quantity            (single field)
 *   php artisan cutover:auto-sync --max-products=100                (cap push count)
 */
class AutoSyncDivergenceCommand extends BaseCommand
{
    /**
     * Commands orchestrated by this auto-sync run.
     *
     * Grep-discoverable. If a new chained command is added (or one is
     * removed), update this list AND the perform() body. The contract is
     * documented in the class-level docblock above.
     */
    private const CHAINED_COMMANDS = [
        'cutover:divergence-scan',
        'products:push-divergence-to-woo',
    ];

    protected $signature = 'cutover:auto-sync
        {--field=stock_quantity,buy_price,category_id : Fields to push (subset of WooFieldComparator pushable set)}
        {--max-products=500 : Cap product count for the push phase (safety)}
        {--skip-scan : Reuse latest divergence-scan correlation_id (default = run fresh scan)}
        {--skip-rescan : Skip the parity-after measurement (for operator manual runs)}
        {--dry-run : Phase 1 scan runs live; phase 2 push dry-run; phases 3-4 skipped}';

    protected $description = 'Nightly MS↔Woo drift self-heal: scan → push → re-scan with parity-regression detection (260611-rl4)';

    public function __construct(private readonly Auditor $auditor)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        // ── 1. Init: per-auto-sync correlation_id (distinct from
        //    divergence-scan's own correlation_id) ─────────────────────────
        $correlationId = (string) Str::ulid();
        Context::add('correlation_id', $correlationId);

        $fields = (string) $this->option('field');
        $maxProducts = max(1, (int) $this->option('max-products'));
        $skipScan = (bool) $this->option('skip-scan');
        $skipRescan = (bool) $this->option('skip-rescan');
        $dryRun = (bool) $this->option('dry-run');

        $counters = [
            'phase' => 'init',
            'fields' => $fields,
            'max_products' => $maxProducts,
            'skip_scan' => $skipScan,
            'skip_rescan' => $skipRescan,
            'dry_run' => $dryRun,
            'parity_before' => null,
            'parity_after' => null,
            'scan_exit' => null,
            'push_exit' => null,
            'rescan_exit' => null,
            'auto_sync_correlation_id' => $correlationId,
        ];

        $this->info(($dryRun ? '[dry-run] ' : '[LIVE] ').'cutover:auto-sync — correlation='.$correlationId);

        // ── 2. Phase 1 — SCAN ───────────────────────────────────────────
        if (! $skipScan) {
            $counters['phase'] = 'scan';
            $scanExit = Artisan::call('cutover:divergence-scan', ['--live' => true]);
            $counters['scan_exit'] = $scanExit;

            if ($scanExit !== 0) {
                $this->auditor->record('cutover.auto_sync_failed', $counters);
                $this->error("Phase 1 SCAN failed (exit={$scanExit}). Aborting.");

                return SymfonyCommand::FAILURE;
            }
        } else {
            $this->line('  phase 1 SCAN SKIPPED (--skip-scan)');
        }

        $counters['parity_before'] = $this->readParityFromSnapshot();

        // ── 3. Phase 2 — PUSH ───────────────────────────────────────────
        $counters['phase'] = 'push';
        $pushOptions = [
            '--field' => $fields,
            '--no-confirm' => true,
            '--limit' => $maxProducts,
        ];
        if ($dryRun) {
            $pushOptions['--dry-run'] = true;
        }

        $pushExit = Artisan::call('products:push-divergence-to-woo', $pushOptions);
        $counters['push_exit'] = $pushExit;

        if ($pushExit !== 0) {
            $this->auditor->record('cutover.auto_sync_failed', $counters);
            $this->error("Phase 2 PUSH failed (exit={$pushExit}). Aborting — phase 3 SKIPPED (planner correction: fail-fast on push error).");

            return SymfonyCommand::FAILURE;
        }

        // ── 4. Phase 3 — RE-SCAN ────────────────────────────────────────
        // Skipped on --dry-run (no actual writes to remeasure) and
        // --skip-rescan (operator escape hatch).
        if (! $dryRun && ! $skipRescan) {
            $counters['phase'] = 'rescan';
            $rescanExit = Artisan::call('cutover:divergence-scan', ['--live' => true]);
            $counters['rescan_exit'] = $rescanExit;

            if ($rescanExit !== 0) {
                $this->auditor->record('cutover.auto_sync_failed', $counters);
                $this->error("Phase 3 RE-SCAN failed (exit={$rescanExit}). Aborting.");

                return SymfonyCommand::FAILURE;
            }

            $counters['parity_after'] = $this->readParityFromSnapshot();
        } else {
            $this->line('  phase 3 RE-SCAN SKIPPED ('.($dryRun ? '--dry-run' : '--skip-rescan').')');
        }

        // ── 5. Phase 4 — REPORT ─────────────────────────────────────────
        $counters['phase'] = 'report';
        $delta = ($counters['parity_before'] !== null && $counters['parity_after'] !== null)
            ? $counters['parity_after'] - $counters['parity_before']
            : null;

        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['fields', $fields],
                ['max_products', (string) $maxProducts],
                ['dry_run', $dryRun ? 'true' : 'false'],
                ['skip_scan', $skipScan ? 'true' : 'false'],
                ['skip_rescan', $skipRescan ? 'true' : 'false'],
                ['parity_before', $counters['parity_before'] === null ? 'n/a' : (string) $counters['parity_before']],
                ['parity_after', $counters['parity_after'] === null ? 'n/a' : (string) $counters['parity_after']],
                ['delta', $delta === null ? 'n/a' : (string) $delta],
                ['auto_sync_correlation_id', $correlationId],
            ],
        );

        $this->auditor->record('cutover.auto_sync_completed', $counters);

        // ── 6. Parity-regression detection ──────────────────────────────
        // Only when BOTH values are present AND not in --dry-run AND not
        // --skip-rescan. A drop in parity is the alarm this whole task
        // exists to surface; exit 1 (NOT 0) so cron logs / Horizon flag
        // the run as failed.
        if (! $dryRun && ! $skipRescan
            && $counters['parity_before'] !== null
            && $counters['parity_after'] !== null
            && $counters['parity_after'] < $counters['parity_before']) {
            $this->auditor->record('cutover.auto_sync_parity_regression', [
                'parity_before' => $counters['parity_before'],
                'parity_after' => $counters['parity_after'],
                'delta' => $delta,
                'auto_sync_correlation_id' => $correlationId,
            ]);
            $this->warn(sprintf(
                'PARITY REGRESSION: parity_after=%d < parity_before=%d (delta=%d). Audit log written. Investigate.',
                $counters['parity_after'],
                $counters['parity_before'],
                (int) $delta,
            ));

            return SymfonyCommand::FAILURE;
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Read the current parity_percent from the dashboard snapshot row that
     * DivergenceScanner writes via upsertByKey('sync_diffs_parity', …).
     *
     * Returns null when the row hasn't been written yet (very first scan in
     * a brand-new install) or when DivergenceScanner couldn't compute a
     * parity (scanned=0 products). Never throws — the parity read is
     * informational, not load-bearing for the chain progression.
     */
    private function readParityFromSnapshot(): ?int
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->first();
        if ($snapshot === null) {
            return null;
        }
        $value = $snapshot->metric_value_json['parity_percent'] ?? null;
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
