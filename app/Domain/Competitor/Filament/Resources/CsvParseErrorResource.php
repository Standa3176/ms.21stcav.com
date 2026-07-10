<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CsvParseErrorResource\Pages;
use App\Domain\Competitor\Models\CsvParseError;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 5 Plan 04a — CsvParseErrorResource (COMP-05 triage surface).
 *
 * Edit-only form: every field is disabled EXCEPT `resolved_at` — admins +
 * pricing_manager mark the row resolved after fixing the source CSV (D-04
 * quarantine resolution path). No create UI — rows are written by the
 * ingest pipeline (Plan 05-02 CompetitorCsvRowWriter + CompetitorWatchCommand).
 *
 * RBAC (via CsvParseErrorPolicy — hand-written, Pitfall P5-F):
 *   - viewAny + view: admin + pricing_manager
 *   - update: admin + pricing_manager (toggle resolved_at)
 *   - delete: admin only
 *   - create: false (ingest-owned)
 *
 * Filters by issue_type (6-enum) + unresolved/resolved ternary to drive the
 * ops triage tabs Plan 05-04b consumes.
 */
class CsvParseErrorResource extends Resource
{
    protected static ?string $model = CsvParseError::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationParentItem = 'Competitor Feeds';

    protected static ?int $navigationSort = 80;

    // 260710-pdw — explicit correct-casing nav label (was auto-humanized 'Csv Parse Errors').
    protected static ?string $navigationLabel = 'CSV Parse Errors';

    protected static ?string $pluralModelLabel = 'CSV Parse Errors';

    protected static ?string $modelLabel = 'CSV Parse Error';

    protected static ?string $recordTitleAttribute = 'filename';

    /**
     * Eager-load competitor so the table's relationship column doesn't N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['competitor']);
    }

    /**
     * Quick task 260504-ev5 — danger badge for unresolved parse errors.
     * Drives the operator to the quarantine triage page when CSV ingest
     * starts producing rows that can't be parsed automatically.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = CsvParseError::query()->whereNull('resolved_at')->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('filename')
                ->disabled()
                ->dehydrated(false),
            Select::make('issue_type')
                ->disabled()
                ->dehydrated(false)
                ->options([
                    CsvParseError::TYPE_AMBIGUOUS_MAPPING => 'Ambiguous mapping',
                    CsvParseError::TYPE_ENCODING_FAILURE => 'Encoding failure',
                    CsvParseError::TYPE_UNPARSEABLE_PRICE => 'Unparseable price',
                    CsvParseError::TYPE_INVALID_SKU_FORMAT => 'Invalid SKU format',
                    CsvParseError::TYPE_INVALID_FILENAME => 'Invalid filename',
                    CsvParseError::TYPE_ORPHAN_SKU => 'Orphan SKU',
                ]),
            TextInput::make('line_number')
                ->disabled()
                ->dehydrated(false)
                ->numeric(),
            Textarea::make('raw_line')
                ->disabled()
                ->dehydrated(false)
                ->rows(3)
                ->columnSpanFull(),
            KeyValue::make('context')
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull(),
            // The ONE writable field — mark resolved after fixing the source CSV.
            DateTimePicker::make('resolved_at')
                ->label('Resolved at')
                ->helperText('Set to now to mark this parse error resolved. Leave blank to reopen.')
                ->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('issue_type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CsvParseError::TYPE_AMBIGUOUS_MAPPING => 'warning',
                        CsvParseError::TYPE_ENCODING_FAILURE => 'danger',
                        CsvParseError::TYPE_UNPARSEABLE_PRICE => 'danger',
                        CsvParseError::TYPE_INVALID_SKU_FORMAT => 'warning',
                        CsvParseError::TYPE_INVALID_FILENAME => 'warning',
                        CsvParseError::TYPE_ORPHAN_SKU => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('filename')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (CsvParseError $r): ?string => $r->filename),
                TextColumn::make('line_number')
                    ->label('Line')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('competitor.name')
                    ->label('Competitor')
                    ->placeholder('— (orphan)')
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->placeholder('Unresolved')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('issue_type')
                    ->multiple()
                    ->options([
                        CsvParseError::TYPE_AMBIGUOUS_MAPPING => 'Ambiguous mapping',
                        CsvParseError::TYPE_ENCODING_FAILURE => 'Encoding failure',
                        CsvParseError::TYPE_UNPARSEABLE_PRICE => 'Unparseable price',
                        CsvParseError::TYPE_INVALID_SKU_FORMAT => 'Invalid SKU format',
                        CsvParseError::TYPE_INVALID_FILENAME => 'Invalid filename',
                        CsvParseError::TYPE_ORPHAN_SKU => 'Orphan SKU',
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCsvParseErrors::route('/'),
            'edit' => Pages\EditCsvParseError::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Parse errors are produced by the ingest pipeline — no UI creation.
        return false;
    }
}
