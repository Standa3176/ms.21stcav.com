<?php

declare(strict_types=1);

namespace App\Domain\Agents\Filament\Resources;

use App\Domain\Agents\Enums\AgentKind;
use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Filament\Resources\AgentRunResource\Pages;
use App\Domain\Agents\Models\AgentRun;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 8 Plan 04 (AGNT-13) — admin-only read-only AgentRun explorer.
 *
 * Mirrors Phase 4's CrmPushLogResource (read-only filtered view): no create,
 * no edit, no delete, no bulk actions. AgentRunPolicy (Plan 01) gates
 * viewAny + view to admin only and denies every mutation.
 *
 * Filters:
 *   - kind        (Select — options from AgentKind enum)
 *   - status      (Select — options from AgentRunStatus enum)
 *   - cost_range  (numeric min/max in pence)
 *   - date_range  (started_at_from / started_at_to)
 *
 * Detail view (ViewAgentRun) renders 6 infolist sections including the
 * "Guardrail Failures" JSON viewer (BLOCKER 1 visualisation) + a Langfuse
 * deep-link if langfuse_trace_id is populated.
 */
class AgentRunResource extends Resource
{
    protected static ?string $model = AgentRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    // Quick task 260504-ev5 — 8-group nav restructure. AgentRuns stay in the
    // Review group at sort 20 (after AutoCreateReviewResource@10).
    protected static ?string $navigationGroup = 'Review';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Agent Runs';

    protected static ?string $modelLabel = 'Agent Run';

    protected static ?string $pluralModelLabel = 'Agent Runs';

    /** AgentRuns are framework-produced; admins never create or mutate. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Quick task 260504-ev5 — danger badge when any agent run failed in the
     * last 24h. Bounded window prevents the badge climbing forever as runs
     * accumulate (5-year retention; D-07). Status enum-cast on the model
     * stores the string value 'failed' in the DB column.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = AgentRun::query()
            ->where('status', AgentRunStatus::Failed->value)
            ->where('started_at', '>=', now()->subDay())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ULID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(12)
                    ->tooltip(fn (AgentRun $r): string => (string) $r->id),

                TextColumn::make('kind')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof AgentRunStatus ? $state->value : (string) $state) {
                        'completed' => 'success',
                        'running' => 'info',
                        'failed' => 'danger',
                        'budget_exceeded' => 'warning',
                        'monthly_budget_blocked' => 'danger',
                        'guardrail_blocked' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('cost_pence')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state): string => '£'.number_format(((int) $state) / 100, 2))
                    ->sortable(),

                TextColumn::make('total_tokens')
                    ->label('Tokens')
                    ->state(fn (AgentRun $r): int => (int) $r->prompt_token_count + (int) $r->completion_token_count),

                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->options(collect(AgentKind::cases())->mapWithKeys(fn (AgentKind $k): array => [
                        $k->value => $k->value,
                    ])->all()),

                SelectFilter::make('status')
                    ->options(collect(AgentRunStatus::cases())->mapWithKeys(fn (AgentRunStatus $s): array => [
                        $s->value => $s->value,
                    ])->all()),

                Filter::make('cost_range')
                    ->form([
                        TextInput::make('min_cost_pence')
                            ->numeric()
                            ->label('Min cost (pence)'),
                        TextInput::make('max_cost_pence')
                            ->numeric()
                            ->label('Max cost (pence)'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        if (isset($data['min_cost_pence']) && $data['min_cost_pence'] !== null && $data['min_cost_pence'] !== '') {
                            $q->where('cost_pence', '>=', (int) $data['min_cost_pence']);
                        }
                        if (isset($data['max_cost_pence']) && $data['max_cost_pence'] !== null && $data['max_cost_pence'] !== '') {
                            $q->where('cost_pence', '<=', (int) $data['max_cost_pence']);
                        }

                        return $q;
                    }),

                Filter::make('date_range')
                    ->form([
                        DatePicker::make('started_at_from')->label('Started from'),
                        DatePicker::make('started_at_to')->label('Started to'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        if (! empty($data['started_at_from'])) {
                            $q->whereDate('started_at', '>=', $data['started_at_from']);
                        }
                        if (! empty($data['started_at_to'])) {
                            $q->whereDate('started_at', '<=', $data['started_at_to']);
                        }

                        return $q;
                    }),
            ])
            ->headerActions([])  // no Create action
            ->bulkActions([])    // no edit/delete
            ->actions([
                \Filament\Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentRuns::route('/'),
            'view' => Pages\ViewAgentRun::route('/{record}'),
        ];
    }
}
