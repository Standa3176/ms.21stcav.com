<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Agents\Models\AgentRun;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8 Plan 05 Task 2 — D-09 sibling command: flag Langfuse trace IDs for
 * deletion when a customer's GDPR erasure also requires upstream trace purge.
 *
 * Open Question Q1 RESOLVED (RESEARCH §Open Questions):
 *   Langfuse's per-trace deletion API is undocumented as of research date.
 *   v2.0 ships this command as a STUB with TODO marker for v2.1.
 *
 * v2.1 paths under evaluation (TODO-V21-LANGFUSE-API):
 *   1. Langfuse REST: PATCH /api/public/projects/{id} (retention override)
 *      OR DELETE /api/public/traces/{id} when stable
 *   2. Direct ClickHouse SQL via worker container (documented in
 *      docs/ops/observability.md as MEDIUM-confidence path):
 *      `docker compose exec clickhouse clickhouse-client --query
 *       "DELETE FROM traces WHERE id IN ({list})"`
 *   3. Manual workaround: delete via Langfuse UI for individual traces
 *
 * Phase 8 ships the safety hook (this command, Log-only execution); v2.1 wires
 * real retention enforcement once the API surface stabilises.
 */
final class AgentsGdprPurgeLangfuseCommand extends BaseCommand
{
    protected $signature = 'agents:gdpr-purge-langfuse
                            {--customer-email= : Customer email whose Langfuse traces should be purged}
                            {--gdpr-log-ulid= : Linking gdpr_erasure_log entry}
                            {--dry-run : Log intent only; do not call Langfuse API}';

    protected $description = 'D-09 — flag Langfuse traces for deletion (STUB — Q1 RESOLVED with TODO-V21-LANGFUSE-API; review observability.md)';

    protected function perform(): int
    {
        $customerEmail = (string) ($this->option('customer-email') ?? '');
        $customerEmail = trim($customerEmail);
        if ($customerEmail === '') {
            $this->error('Missing --customer-email');

            return 1;
        }
        $gdprLogUlid = (string) ($this->option('gdpr-log-ulid') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $normalised = mb_strtolower($customerEmail);
        $traceIds = AgentRun::query()
            ->where(function ($q) use ($normalised) {
                // Driver-portable substring match (was MariaDB-only JSON_SEARCH,
                // absent on SQLite). LOWER(tool_calls) LIKE is broader-or-equal —
                // never misses the email — and runs on both SQLite + MariaDB.
                $q->whereRaw('LOWER(tool_calls) LIKE ?', ['%'.$normalised.'%'])
                    ->orWhere('agent_reasoning_summary', 'like', '%'.$normalised.'%');
            })
            ->whereNotNull('langfuse_trace_id')
            ->pluck('langfuse_trace_id')
            ->all();

        $this->warn('STUB (Q1 RESOLVED): Langfuse per-trace deletion API not yet validated.');
        $this->warn('  TODO-V21-LANGFUSE-API: swap to live API or fall back to ClickHouse SQL per docs/ops/observability.md.');
        $this->info('Found '.count($traceIds).' trace(s) to flag for deletion');

        foreach ($traceIds as $traceId) {
            $this->info('  trace_id='.$traceId);
            if (! $dryRun) {
                // TODO-V21-LANGFUSE-API: replace with Langfuse SDK call once
                // the API path is validated. Fallback: direct ClickHouse SQL
                // via worker container (see docs/ops/observability.md §GDPR
                // purge of Langfuse traces).
                Log::info('agents:gdpr-purge-langfuse: flagged for deletion', [
                    'trace_id' => $traceId,
                    'gdpr_log_ulid' => $gdprLogUlid,
                ]);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Done.');

        return 0;
    }
}
