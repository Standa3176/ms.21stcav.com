<?php

declare(strict_types=1);

namespace App\Domain\Agents\Filament\Resources\AgentRunResource\Pages;

use App\Domain\Agents\Filament\Resources\AgentRunResource;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * Phase 8 Plan 04 — AgentRun detail view (AGNT-13).
 *
 * Six infolist sections give admins everything they need to forensically
 * investigate a single run without leaving Filament:
 *
 *   1. Identity        — id / kind / status / started_at / completed_at /
 *                         triggering_correlation_id
 *   2. Cost & Tokens   — cost_pence (formatted £) / prompt+completion tokens /
 *                         finish_reason
 *   3. System Prompt   — system_prompt_hash + truncated agent_reasoning_summary
 *   4. Tool Calls      — JSON viewer over tool_calls[] (4KB-truncated entries)
 *   5. Guardrail Fails — visualises the BLOCKER 1 JSON column (only renders
 *                         if guardrail_failures is non-null — most runs have null)
 *   6. Langfuse Trace  — clickable URL to the self-hosted Langfuse trace
 *                         (only renders if langfuse_trace_id present)
 *   7. Linked Suggestions — repeater listing every Suggestion proposed_by this run
 */
class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label('ULID')->fontFamily('mono')->copyable(),
                    TextEntry::make('kind')->badge(),
                    TextEntry::make('status')->badge()
                        ->color(fn ($state): string => match ((string) (is_object($state) ? $state->value : $state)) {
                            'completed' => 'success',
                            'running' => 'info',
                            'failed', 'monthly_budget_blocked' => 'danger',
                            'budget_exceeded', 'guardrail_blocked' => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('triggering_correlation_id')->label('Correlation ID')->fontFamily('mono')->copyable()->placeholder('—'),
                    TextEntry::make('triggering_suggestion_id')->label('Triggering Suggestion')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                ]),

            Section::make('Cost & Tokens')
                ->columns(4)
                ->schema([
                    TextEntry::make('cost_pence')
                        ->label('Cost')
                        ->formatStateUsing(fn ($state): string => '£'.number_format(((int) $state) / 100, 2)),
                    TextEntry::make('prompt_token_count')->label('Prompt tokens'),
                    TextEntry::make('completion_token_count')->label('Completion tokens'),
                    TextEntry::make('finish_reason')->placeholder('—'),
                ]),

            Section::make('System Prompt')
                ->collapsed()
                ->schema([
                    TextEntry::make('system_prompt_hash')
                        ->label('SHA-256')
                        ->fontFamily('mono')
                        ->copyable(),
                    TextEntry::make('agent_reasoning_summary')
                        ->label('Reasoning summary (truncated 8KB)')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Tool Calls')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('tool_calls')
                        ->keyLabel('Index')
                        ->valueLabel('Tool call')
                        ->placeholder('(no tool calls recorded)'),
                ]),

            // Plan-checker iter 1 BLOCKER 1 — visualise the new column.
            // Hidden when null so a normal completed run doesn't render a
            // confusing empty section. ->visible() reads the underlying
            // record so the gate is per-row.
            Section::make('Guardrail Failures')
                ->visible(fn (AgentRun $record): bool => ! empty($record->guardrail_failures))
                ->schema([
                    KeyValueEntry::make('guardrail_failures')
                        ->keyLabel('Index')
                        ->valueLabel('Failure entry'),
                ]),

            Section::make('Langfuse Trace')
                ->visible(fn (AgentRun $record): bool => ! empty($record->langfuse_trace_id))
                ->schema([
                    TextEntry::make('langfuse_trace_id')
                        ->label('Trace ID')
                        ->fontFamily('mono')
                        ->copyable()
                        ->url(fn (AgentRun $record): ?string => self::langfuseTraceUrl($record))
                        ->openUrlInNewTab(),
                ]),

            Section::make('Linked Suggestions')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('linked_suggestions_summary')
                        ->state(fn (AgentRun $record): array => self::linkedSuggestionsSummary($record))
                        ->keyLabel('Suggestion ID')
                        ->valueLabel('Kind / Status / Correlation')
                        ->placeholder('(no Suggestions linked to this run)'),
                ]),
        ]);
    }

    /**
     * Resolve the Langfuse trace URL using the configured host. Returns null
     * when host config is missing so the TextEntry renders as plain text
     * instead of a broken link.
     */
    private static function langfuseTraceUrl(AgentRun $record): ?string
    {
        $host = (string) config('agents.observability.langfuse.host', '');
        if ($host === '' || empty($record->langfuse_trace_id)) {
            return null;
        }

        return rtrim($host, '/').'/trace/'.$record->langfuse_trace_id;
    }

    /**
     * Compose a "id => 'kind / status / cid'" map of every Suggestion
     * proposed_by this AgentRun. Filament's KeyValueEntry renders it as a
     * read-only summary table; admins click an ID to copy and search the
     * Suggestions inbox.
     *
     * @return array<string, string>
     */
    private static function linkedSuggestionsSummary(AgentRun $record): array
    {
        $rows = Suggestion::query()
            ->where('proposed_by_type', AgentRun::class)
            ->where('proposed_by_id', $record->id)
            ->orderByDesc('proposed_at')
            ->limit(50)
            ->get(['id', 'kind', 'status', 'correlation_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->id] = sprintf(
                '%s / %s / cid:%s',
                (string) $row->kind,
                (string) $row->status,
                substr((string) $row->correlation_id, 0, 8),
            );
        }

        return $out;
    }
}
