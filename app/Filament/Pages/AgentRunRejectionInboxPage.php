<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Suggestions\Models\Suggestion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 10 Plan 05 D-09 — Agent Run Rejection Inbox.
 *
 * Single-purpose triage page for rejected margin_change Suggestions that
 * were enriched by the PricingAgent and given structured rejection feedback
 * via the SuggestionResource reject form (Plan 10-05 Task 1).
 *
 * Live route: /admin/agent-runs/rejection-inbox
 *
 * Auto-discovered via AdminPanelProvider's
 *   ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
 *
 * Access control:
 *   - canAccess() returns TRUE for admin + pricing_manager roles ONLY
 *   - sales + read_only get a Filament 403 in the panel
 *   - The Shield run_pricing_agent permission is the ops-side gate (seeded
 *     by Plan 10-05 RolePermissionSeeder); the role-check here is the
 *     belt-and-braces UI gate. Both must agree for the page to surface.
 *
 * Query:
 *   Suggestion::query()
 *     ->where('kind', 'margin_change')
 *     ->where('status', STATUS_REJECTED)
 *     ->whereNotNull('agent_rejection_feedback')   // column-canonical (Plan 10-05 Step B)
 *
 * Bulk action `mark_triaged`:
 *   Appends triaged_at + triage_note + triaged_by_user_id onto each selected
 *   row's agent_rejection_feedback JSON. Allows the prompt-iteration team to
 *   tag rejections they've already actioned (prompt edit shipped, fixture
 *   added, etc.) without losing the row from the inbox query.
 */
final class AgentRunRejectionInboxPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.agent-run-rejection-inbox';

    protected static ?string $navigationGroup = 'Review';

    protected static ?string $title = 'Agent Run Rejection Inbox';

    protected static ?string $slug = 'agent-runs/rejection-inbox';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    /**
     * Page-level RBAC. Mirrors Phase 8 AgentRunResource pattern: a hand-written
     * gate is the load-bearing layer; the Shield permission seeder
     * (Plan 10-05 Task 2) is the secondary defence. sales + read_only get a
     * 403 because the inbox surfaces prompt-iteration intel that informs
     * pricing decisions.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * Filament Page navigation visibility (sidebar + nav search). Matches
     * canAccess() so the link doesn't render for users who can't open it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Suggestion::query()
                    ->where('kind', 'margin_change')
                    ->where('status', Suggestion::STATUS_REJECTED)
                    ->whereNotNull('agent_rejection_feedback')
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->limit(8)
                    ->fontFamily('mono')
                    ->copyable()
                    ->tooltip(fn (Suggestion $r): string => (string) $r->id),
                TextColumn::make('evidence.sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->searchable(query: fn ($query, string $search) => $query->where('evidence->sku', 'like', "%{$search}%")),
                TextColumn::make('agent_rejection_feedback.misleading')
                    ->label('Misleading?')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'yes' => 'danger',
                        'partial' => 'warning',
                        'no' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('evidence.agent_confidence_0_to_100')
                    ->label('Confidence')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (int) $state >= 71 => 'success',
                        (int) $state >= 31 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('evidence.proposed_margin_bps')
                    ->label('v1 bps')
                    ->numeric(),
                TextColumn::make('agent_band')
                    ->label('Agent band')
                    ->state(fn (Suggestion $r): string => sprintf(
                        '%d–%d',
                        (int) data_get($r->evidence, 'agent_proposed_band_min_bps', 0),
                        (int) data_get($r->evidence, 'agent_proposed_band_max_bps', 0),
                    )),
                TextColumn::make('agent_rejection_feedback.notes')
                    ->label('Notes')
                    ->limit(60)
                    ->tooltip(fn (Suggestion $r): string => (string) data_get($r->agent_rejection_feedback, 'notes', '')),
                TextColumn::make('triaged')
                    ->label('Triaged')
                    ->state(fn (Suggestion $r): string => data_get($r->agent_rejection_feedback, 'triaged_at') ? '✓' : '—')
                    ->badge()
                    ->color(fn (string $state): string => $state === '✓' ? 'success' : 'gray'),
                TextColumn::make('resolved_at')
                    ->label('Rejected at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('days_since_rejection')
                    ->label('Days ago')
                    ->state(fn (Suggestion $r): int => (int) ($r->resolved_at?->diffInDays(now()) ?? 0))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('misleading')
                    ->label('Misleading flag')
                    ->options([
                        'yes' => 'Yes',
                        'no' => 'No',
                        'partial' => 'Partially',
                    ])
                    ->query(function ($query, array $data) {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        // JSON-extract path query — the rejection inbox column
                        // is JSON; misleading is the top-level radio value.
                        return $query->where('agent_rejection_feedback->misleading', $data['value']);
                    }),
                Filter::make('resolved_at')
                    ->label('Rejected date range')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $d) => $q->whereDate('resolved_at', '>=', $d)
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($q, $d) => $q->whereDate('resolved_at', '<=', $d)
                            );
                    }),
                Filter::make('untriaged')
                    ->label('Untriaged only')
                    ->toggle()
                    ->query(fn ($query, array $data) => ($data['isActive'] ?? false)
                        ? $query->whereRaw("JSON_EXTRACT(agent_rejection_feedback, '$.triaged_at') IS NULL")
                        : $query),
            ])
            ->bulkActions([
                BulkAction::make('mark_triaged')
                    ->label('Mark triaged')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Add a triage note (≥5 chars) — visible in the inbox + agent_rejection_feedback JSON for the selected rows.')
                    ->form([
                        Textarea::make('triage_note')
                            ->label('Triage note')
                            ->required()
                            ->minLength(5)
                            ->maxLength(2000)
                            ->helperText('e.g. "prompt edit shipped in 87fed12; fixture added", "moved to Phase 11 backlog as quote-time concern".'),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        foreach ($records as $record) {
                            /** @var Suggestion $record */
                            $feedback = (array) $record->agent_rejection_feedback;
                            $feedback['triaged_at'] = now()->toIso8601String();
                            $feedback['triage_note'] = (string) $data['triage_note'];
                            $feedback['triaged_by_user_id'] = (int) auth()->id();

                            $record->update([
                                'agent_rejection_feedback' => $feedback,
                            ]);
                        }
                    }),
            ])
            ->defaultSort('resolved_at', 'desc');
    }
}
