<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Agents\Enums\AgentKind;
use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Enums\FinishReason;
use Database\Factories\Domain\Agents\AgentRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 8 Plan 01 — canonical AgentRun forensics row (D-06 + D-07 + D-09).
 *
 * 15-column self-contained snapshot: every Anthropic call lands one of these,
 * with structured tool-call history + cost + token counts + status. Per D-06
 * this row is independent of v1 audit_log (which prunes at 365d) and of
 * Langfuse traces (which prune at 90d) — AgentRun retention is 5 years (D-07).
 *
 * Writes flow ONLY through Plan 04's `RunAgentJob` (the framework writer);
 * `AgentsWriteOnlyViaSuggestionsTest` excludes this model from its grep so
 * the framework's own status/cost/token persistence is allowed. Every other
 * Agents-domain class must write via the Suggestions seam, not direct DB.
 *
 * LogsActivity captures only `status` and `completed_at` dirty changes
 * (D-06). Token counts and cost mutate during the multi-tool-call loop and
 * would flood the activity_log; the structured-summary snapshot in this row
 * is the audit source of truth instead.
 *
 * GDPR scrub-in-place per D-09 — `gdprScrubInPlace()` is a stub here (Plan
 * 05's `AgentRunGdprScrubber` ships the real implementation). The Phase 4
 * GDPR command extends in Plan 05 to call this method when erasing a
 * customer's bearing AgentRun rows.
 *
 * @property string $id                              26-char ULID PK
 * @property AgentKind $kind
 * @property AgentRunStatus $status
 * @property string|null $triggering_suggestion_id   ULID nullable
 * @property string|null $triggering_correlation_id  varchar(36) — v1 correlation_id parity
 * @property string $system_prompt_hash              char(64) sha256 of rendered Blade prompt
 * @property array $tool_calls                       per-step Prism tool-call history
 * @property string|null $agent_reasoning_summary    text — agent's final output (truncated 8KB)
 * @property FinishReason|null $finish_reason
 * @property int $prompt_token_count
 * @property int $completion_token_count
 * @property int $cost_pence
 * @property string|null $langfuse_trace_id          varchar(64) — advisory after 90d Langfuse prune
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property array|null $guardrail_failures          ROADMAP success criterion #4 — pre/post violations
 */
final class AgentRun extends Model
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    protected $table = 'agent_runs';

    protected $fillable = [
        'kind',
        'status',
        'triggering_suggestion_id',
        'triggering_correlation_id',
        'system_prompt_hash',
        'tool_calls',
        'agent_reasoning_summary',
        'finish_reason',
        'prompt_token_count',
        'completion_token_count',
        'cost_pence',
        'langfuse_trace_id',
        'started_at',
        'completed_at',
        'guardrail_failures',
    ];

    protected $casts = [
        'kind' => AgentKind::class,
        'status' => AgentRunStatus::class,
        'finish_reason' => FinishReason::class,
        'tool_calls' => 'array',
        'guardrail_failures' => 'array',
        'prompt_token_count' => 'integer',
        'completion_token_count' => 'integer',
        'cost_pence' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Per CONTEXT D-06: only `status` and `completed_at` flow into activity_log.
     * Token counts and cost mutate during the tool-call loop and would flood
     * the audit table; the row itself is the structured-summary source of truth.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'completed_at'])
            ->logOnlyDirty();
    }

    /**
     * Phase 8 Plan 05 stub — GDPR scrub-in-place (D-09). Plan 05's
     * `AgentRunGdprScrubber` ships the real implementation that:
     *   - replaces `tool_calls` PII with REDACTED-{sha256-prefix} tokens
     *   - replaces `agent_reasoning_summary` with `[scrubbed per GDPR erasure {gdpr_log_ulid}]`
     *   - preserves cost_pence + token counts + kind + timestamps
     *   - preserves langfuse_trace_id (separate erasure pathway via agents:gdpr-purge-langfuse)
     *
     * @throws \LogicException always — Plan 05 implements.
     */
    public function gdprScrubInPlace(string $gdprLogUlid): void
    {
        throw new \LogicException('AgentRun::gdprScrubInPlace is implemented in Phase 8 Plan 05 (AgentRunGdprScrubber).');
    }

    protected static function newFactory(): AgentRunFactory
    {
        return AgentRunFactory::new();
    }
}
