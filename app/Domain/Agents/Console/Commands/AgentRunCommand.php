<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Agents\Jobs\RunAgentJob;
use Illuminate\Support\Facades\Context;

/**
 * Phase 8 Plan 04 — `agent:run {kind} [--dry-run] [--input=]` CLI entry point.
 *
 * Extends BaseCommand so correlation_id threads through the entire run
 * (Context::add inside BaseCommand::handle). The correlation arrives at
 * RunAgentJob → AgentRun.triggering_correlation_id → Suggestion.correlation_id
 * via AgentSuggestionWriter, completing the audit chain.
 *
 * --dry-run:
 *   - dispatches the job synchronously (dispatch_sync)
 *   - shadow-mode (AGENT_WRITE_ENABLED=false) writes Suggestion.status='shadow'
 *   - perfect for AGNT-12 acceptance: a developer or admin can verify
 *     framework health without creating real pending suggestions
 *
 * Without --dry-run:
 *   - dispatches the job onto the 'agents' queue (Plan 01 Horizon supervisor)
 *   - if AGENT_WRITE_ENABLED=true, real Suggestions land in the inbox
 *
 * --input lets the operator pass a JSON payload that becomes the agent's
 * input array. Defaults to '{}'. Invalid JSON exits 1 with a clear error.
 */
final class AgentRunCommand extends BaseCommand
{
    protected $signature = 'agent:run
                            {kind : Agent kind to dispatch (e.g. echo, pricing)}
                            {--dry-run : Run synchronously instead of dispatching to the agents queue}
                            {--input= : Optional JSON-encoded input payload (default: {})}';

    protected $description = 'Dispatch an agent run; --dry-run executes synchronously for verification';

    protected function perform(): int
    {
        $kind = (string) $this->argument('kind');
        $inputJson = (string) ($this->option('input') ?? '{}');

        try {
            $input = json_decode($inputJson, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                $input = [];
            }
        } catch (\JsonException $e) {
            $this->error("Invalid --input JSON: {$e->getMessage()}");

            return 1;
        }

        $job = new RunAgentJob(
            kind: $kind,
            input: $input,
            triggeringSuggestionId: null,
            triggeringCorrelationId: (string) (Context::get('correlation_id') ?? ''),
        );

        try {
            if ($this->option('dry-run')) {
                $this->info("Running {$kind} synchronously (--dry-run)...");
                dispatch_sync($job);
            } else {
                $this->info("Dispatching {$kind} to agents queue...");
                dispatch($job);
            }
            $this->info('OK');

            return 0;
        } catch (\Throwable $e) {
            $this->error('Agent run failed: '.$e->getMessage());

            return 1;
        }
    }
}
