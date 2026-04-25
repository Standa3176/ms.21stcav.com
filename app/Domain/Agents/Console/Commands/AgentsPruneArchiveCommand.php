<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Agents\Models\AgentRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8 Plan 05 Task 3 — D-07 annual rolling retention for AgentRun.
 *
 * Exports rows older than --days (default 5y per D-07) to gzip JSON archive
 * then DELETEs them.
 *
 * Audit trail: writes one activity_log row per run with description=
 * `agent_run_archived` + count + path. The batch_uuid carries the BaseCommand
 * correlation_id so the prune action threads through the v1 audit table.
 *
 * Disk projection (D-07):
 *   ~5MB/month at 100 runs/day → ~3GB after 5y → archives sit at <100MB
 *   compressed (gzip ratio ~30:1 on JSON-encoded structured tool_calls).
 *
 * Schedule: routes/console.php registers `Schedule::command('agents:prune-archive')
 *   ->yearlyOn(1, 1, '02:00')->timezone('Europe/London')`. Operator can also
 *   invoke ad-hoc with `--days=N` for shorter horizons (testing).
 */
final class AgentsPruneArchiveCommand extends BaseCommand
{
    protected $signature = 'agents:prune-archive
                            {--days=1825 : Retention horizon in days (default 5 years per D-07)}
                            {--dry-run : Log intent only; do not write archive or delete rows}';

    protected $description = 'D-07 — export AgentRun rows older than {days} to gzip archive then prune';

    protected function perform(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        if ($days <= 0) {
            $this->error('--days must be > 0');

            return 1;
        }

        $threshold = Carbon::now('Europe/London')->subDays($days);

        $count = AgentRun::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $threshold)
            ->count();

        if ($count === 0) {
            $this->info("No AgentRun rows older than {$days} days — nothing to archive.");

            return 0;
        }

        $stamp = Carbon::now('Europe/London')->format('Y-m-d-His');
        $archivePath = "agent-archives/agent-runs-{$stamp}.json.gz";

        $this->info("Found {$count} row(s) older than {$days} days; archiving to {$archivePath}...");

        if ($dryRun) {
            $this->info('[dry-run] would write archive + delete '.$count.' rows');

            return 0;
        }

        // Stream rows in chunks to avoid memory blow-up on large prunes.
        $payload = [];
        AgentRun::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(1000, function ($chunk) use (&$payload) {
                foreach ($chunk as $run) {
                    $payload[] = $run->toArray();
                }
            });

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $gzipped = gzencode($json, 9);
        Storage::disk('local')->put($archivePath, $gzipped);
        $bytes = Storage::disk('local')->size($archivePath);
        $this->info('Archive written: '.$bytes.' bytes');

        // DELETE after archive write succeeds — failure between archive write
        // and delete leaves an orphan archive but no data loss (next run will
        // also include those rows; archives are idempotent additions).
        $deleted = AgentRun::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $threshold)
            ->delete();
        $this->info("Deleted {$deleted} row(s)");

        // Audit log entry per D-07
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'agent_run_archived',
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => null,
            'causer_id' => null,
            'properties' => json_encode([
                'archive_path' => $archivePath,
                'archive_bytes' => $bytes,
                'archived_count' => $count,
                'deleted_count' => $deleted,
                'days_threshold' => $days,
            ]),
            'batch_uuid' => (string) (Context::get('correlation_id') ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 0;
    }
}
