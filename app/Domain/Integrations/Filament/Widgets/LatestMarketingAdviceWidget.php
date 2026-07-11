<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Widgets;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 15 Plan 15b-02 Task 4 — Latest marketing advice (READ-ONLY table).
 *
 * PURE PRESENTATION over the ad_optimisation Suggestions produced by the 15b-01
 * AdOptimisationAgent. Lists the most-recent PENDING ad_optimisation Suggestions
 * (created_at desc, limit 10). Each row surfaces the FIRST bundled proposal's
 * action_type / target / confidence (the agent writes ONE bundled Suggestion
 * carrying payload.proposals[]). READ-ONLY — approve/reject stays in the
 * Suggestions inbox; a header link deep-links there filtered to the kind.
 *
 * Zero-safe / empty-state: with ZERO ad_optimisation Suggestions the table
 * renders its empty state — never an error.
 */
final class LatestMarketingAdviceWidget extends TableWidget
{
    protected static ?string $heading = 'Latest marketing advice';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Any authed workspace user may read the advice inbox surface.
        return auth()->user()?->can('viewAny', Suggestion::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Proposed')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('action_type')
                    ->label('Action')
                    ->badge()
                    ->getStateUsing(fn (Suggestion $record): string => str_replace(
                        '_',
                        ' ',
                        (string) ($record->payload['proposals'][0]['action_type'] ?? '—'),
                    )),
                TextColumn::make('target')
                    ->label('Target')
                    ->limit(48)
                    ->getStateUsing(fn (Suggestion $record): string => (string) ($record->payload['proposals'][0]['target'] ?? '—')),
                TextColumn::make('confidence')
                    ->label('Confidence')
                    ->badge()
                    ->getStateUsing(fn (Suggestion $record): string => (string) ($record->payload['proposals'][0]['confidence'] ?? '—'))
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('proposals_count')
                    ->label('Proposals')
                    ->getStateUsing(fn (Suggestion $record): int => count((array) ($record->payload['proposals'] ?? []))),
            ])
            ->headerActions([
                Action::make('open_inbox')
                    ->label('Open in Suggestions inbox')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('gray')
                    ->url(SuggestionResource::getUrl('index', [
                        'tableFilters' => ['kind' => ['value' => 'ad_optimisation']],
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('No marketing advice yet')
            ->emptyStateDescription('Run "Review with Claude" (or wait for the scheduled run) once Google Analytics 4 is connected — advice appears here.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    private function baseQuery(): Builder
    {
        return Suggestion::query()
            ->where('kind', 'ad_optimisation')
            ->where('status', Suggestion::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->limit(10);
    }
}
