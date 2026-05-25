<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\ImportIssueResource\Pages;
use App\Domain\Sync\Models\ImportIssue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 2 Plan 02-04 — SYNC-12 Import Issues triage Resource.
 *
 * Pricing_manager + admin own triage (D-02 role split + D-09).
 * Read-only for sales + read_only.
 *
 * Filter by issue_type lets ops isolate each of the 4 triage flows; ternary
 * "resolved" filter separates backlog from closed.
 *
 * Bulk "Mark resolved" action gates via ->authorize + ->visible on hasAnyRole
 * (admin, pricing_manager) — Warning 9 defence-in-depth.
 */
class ImportIssueResource extends Resource
{
    protected static ?string $model = ImportIssue::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    // Quick task 260504-ev5 — 8-group nav restructure. Import issues come
    // from Woo↔supplier sync — moved into 'WooCommerce' group at sort 20.
    protected static ?string $navigationGroup = 'Sync & CRM';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'sku';

    protected static ?string $pluralModelLabel = 'Import Issues';

    /**
     * Quick task 260504-ev5 — warning badge for unresolved import issues.
     * Mirrors the table's TernaryFilter "Unresolved" path; pricing_manager
     * triages these via the bulk Mark Resolved action.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = ImportIssue::query()->whereNull('resolved_at')->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // SKU + issue_type come from the sync pipeline — disabled in UI except
            // for admin. pricing_manager edits notes + resolved_at only.
            TextInput::make('sku')
                ->required()
                ->disabled(fn () => ! (auth()->user()?->hasRole('admin') ?? false)),
            Select::make('issue_type')
                ->options([
                    ImportIssue::TYPE_MISSING_AT_SUPPLIER => 'Missing at supplier',
                    ImportIssue::TYPE_UNKNOWN_SKU => 'Unknown SKU',
                    ImportIssue::TYPE_MISSING_COST_PRICE => 'Missing cost/price',
                    ImportIssue::TYPE_EXCLUDE_FLAG_NO_METADATA => 'Exclude flag w/ no metadata',
                ])
                ->required()
                ->disabled(fn () => ! (auth()->user()?->hasRole('admin') ?? false)),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('issue_type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        ImportIssue::TYPE_MISSING_AT_SUPPLIER => 'warning',
                        ImportIssue::TYPE_UNKNOWN_SKU => 'primary',
                        ImportIssue::TYPE_MISSING_COST_PRICE => 'danger',
                        ImportIssue::TYPE_EXCLUDE_FLAG_NO_METADATA => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('woo_product_id')->label('Woo product id')->placeholder('—'),
                TextColumn::make('detected_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->placeholder('Unresolved'),
                TextColumn::make('notes')->limit(50)->placeholder('—'),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->limit(12)
                    ->copyable(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->filters([
                SelectFilter::make('issue_type')->multiple()->options([
                    ImportIssue::TYPE_MISSING_AT_SUPPLIER => 'Missing at supplier',
                    ImportIssue::TYPE_UNKNOWN_SKU => 'Unknown SKU',
                    ImportIssue::TYPE_MISSING_COST_PRICE => 'Missing cost/price',
                    ImportIssue::TYPE_EXCLUDE_FLAG_NO_METADATA => 'Exclude flag no metadata',
                ]),
                TernaryFilter::make('resolved')
                    ->nullable()
                    ->placeholder('All')
                    ->trueLabel('Resolved')
                    ->falseLabel('Unresolved')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('resolved_at'),
                        false: fn ($q) => $q->whereNull('resolved_at'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->bulkActions([
                // Warning 9 defence-in-depth: authorize() hard-gates at POST even
                // if a crafted request bypasses UI visibility. Sales + read_only 403.
                BulkAction::make('markResolved')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                    ->action(function (Collection $records): void {
                        $records->each(fn (ImportIssue $issue) => $issue->update(['resolved_at' => now()]));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportIssues::route('/'),
            'edit' => Pages\EditImportIssue::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // ImportIssues are produced by the sync pipeline — no UI creation.
        return false;
    }
}
