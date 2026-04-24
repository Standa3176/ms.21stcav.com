<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\RollbackDrill;

/**
 * Phase 7 Plan 05 Task 2 — CUT-05 rollback drill artisan entry.
 *
 * Dry-run (default) — simulate all 5 steps, write drill report:
 *   php artisan cutover:drill-rollback
 *
 * Live (STAGING-ONLY) — requires CUTOVER_DRILL_ALLOWED=true:
 *   CUTOVER_DRILL_ALLOWED=true php artisan cutover:drill-rollback --live
 *
 * D-17 env-gate: command FAILS FAST with "Drill not allowed in this environment"
 * when --live is passed without the env var. The config stores the env-var
 * NAME (cutover.drill_allowed_env_var = 'CUTOVER_DRILL_ALLOWED') so ops
 * setting CUTOVER_DRILL_ALLOWED=true alone doesn't auto-approve anything
 * — this command must explicitly read the config key (two-step safety).
 */
class DrillRollbackCommand extends BaseCommand
{
    protected $signature = 'cutover:drill-rollback
        {--live : Execute live drill (requires CUTOVER_DRILL_ALLOWED=true — STAGING ONLY)}';

    protected $description = 'Simulate the cutover rollback playbook (CUT-05; --live is staging-only)';

    public function perform(): int
    {
        $drill = app(RollbackDrill::class);
        $live = (bool) $this->option('live');

        if ($live) {
            $envVarName = (string) config('cutover.drill_allowed_env_var', 'CUTOVER_DRILL_ALLOWED');
            $envValue = env($envVarName);
            if ($envValue !== true && $envValue !== 'true' && $envValue !== '1' && $envValue !== 1) {
                $this->error(sprintf(
                    'Drill not allowed in this environment. Set %s=true in .env (staging only).',
                    $envVarName,
                ));

                return self::FAILURE;
            }
        }

        $results = $drill->run($live);

        $this->info($live ? 'LIVE drill completed:' : 'DRY-RUN drill completed:');
        foreach (['step_1_flag_readable', 'step_2_flag_flip_simulated', 'step_3_backup_verifiable', 'step_4_legacy_cron_check', 'step_5_drill_report'] as $stepKey) {
            $status = $results[$stepKey] ?? 'UNKNOWN';
            $this->line(sprintf('  %s — %s', $stepKey, $status));
        }
        $this->line('Drill report written to: '.($results['report_path'] ?? '(none)'));

        return self::SUCCESS;
    }
}
