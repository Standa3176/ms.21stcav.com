<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Pages;

use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CsvParseError;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Phase 5 Plan 04b — CsvIngestIssuesPage (COMP-05 — the ONLY manual config
 * surface in the whole pipeline per D-04).
 *
 * Four tabs, each a filtered view over `csv_parse_errors`:
 *   - Quarantine     (issue_type = ambiguous_mapping, unresolved)
 *   - Orphans        (issue_type = orphan_sku, unresolved — cross-link to
 *                     SuggestionResource for new_product_opportunity)
 *   - Encoding errors (issue_type = encoding_failure, unresolved)
 *   - Value errors   (issue_type IN (unparseable_price, invalid_sku_format,
 *                     invalid_filename), unresolved)
 *
 * Quarantine-tab "Resolve" action:
 *   - Opens a modal with first-10-rows preview of the quarantined CSV
 *   - Prompts for SKU column index + price column index + decimal format
 *   - Submit creates / updates a CompetitorCsvMapping row (D-03)
 *   - Moves the file from storage/app/competitors/quarantine/{Y-m-d}/ or
 *     storage/app/competitors/quarantine/ to storage/app/competitors/incoming/
 *     so the next CompetitorWatchCommand tick re-picks it up and dispatches
 *     IngestCompetitorCsvJob with the new mapping applied
 *   - Marks csv_parse_errors.resolved_at = now()
 *
 * RBAC:
 *   - canAccess: viewAny on CsvParseError (admin + pricing_manager)
 *   - Resolve action ->authorize: update on CompetitorCsvMapping (D-04 —
 *     pricing_manager can resolve quarantined files)
 */
class CsvIngestIssuesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    // Quick task 260504-ev5 — 8-group nav restructure. Sits next to
    // CsvParseErrorResource in 'FTP & CSV' at sort 50 (last in group).
    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationParentItem = 'Competitor Feeds';

    // 260710-pdw — de-collided among the Competitor Feeds children (was 50,
    // colliding with FTP Credentials@50). Now 55.
    protected static ?int $navigationSort = 55;

    protected static ?string $navigationLabel = 'CSV Ingest Issues';

    protected static ?string $title = 'CSV Ingest Issues';

    protected static string $view = 'filament.pages.csv-ingest-issues';

    public string $activeTab = 'quarantine';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CsvParseError::class) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** Tab keys → human label mapping used by the Blade view. */
    public function getTabs(): array
    {
        return [
            'quarantine' => 'Quarantine',
            'orphans' => 'Orphans',
            'encoding' => 'Encoding errors',
            'values' => 'Value errors',
        ];
    }

    public function setActiveTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->getTabs())) {
            return;
        }
        $this->activeTab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scopedQuery())
            ->columns([
                TextColumn::make('filename')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (CsvParseError $r): ?string => $r->filename),
                TextColumn::make('competitor.name')
                    ->label('Competitor')
                    ->placeholder('— (unknown)'),
                TextColumn::make('line_number')
                    ->label('Line')
                    ->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('issue_type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions($this->tableActionsForActiveTab())
            ->paginated([10, 25, 50]);
    }

    /**
     * @return array<int, Action>
     */
    protected function tableActionsForActiveTab(): array
    {
        if ($this->activeTab !== 'quarantine') {
            return [];
        }

        return [$this->makeResolveAction()];
    }

    private function makeResolveAction(): Action
    {
        return Action::make('resolve')
            ->label('Resolve mapping')
            ->icon('heroicon-o-wrench-screwdriver')
            ->modalHeading('Resolve column mapping')
            ->modalSubmitActionLabel('Save mapping and re-queue')
            // D-04 — admin + pricing_manager may update mappings. Passing the
            // class string to Gate::check routes to CompetitorCsvMappingPolicy::update
            // whose second argument requires a model instance — so we pass a fresh
            // (unpersisted) instance purely as a role-gate carrier.
            ->authorize(
                fn (): bool => auth()->user()?->can('update', new CompetitorCsvMapping) ?? false
            )
            ->form(function (CsvParseError $record): array {
                $src = self::locateQuarantinedFile($record->filename);
                $rows = [];
                if ($src !== null && is_file($src)) {
                    try {
                        $rows = collect(
                            SimpleExcelReader::create($src)->noHeaderRow()->getRows()
                        )->take(10)->map(fn ($r) => array_values((array) $r))->all();
                    } catch (Throwable $e) {
                        Log::warning('CsvIngestIssuesPage.preview_failed', [
                            'filename' => $record->filename,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $header = $rows[0] ?? [];
                $options = [];
                foreach (array_values($header) as $i => $value) {
                    $options[(string) $i] = sprintf('[%d] %s', $i, (string) $value);
                }
                if ($options === []) {
                    // No file / empty CSV — fall back to positional indexes 0-4
                    // so the form is still submittable after operator confirms.
                    for ($i = 0; $i < 5; $i++) {
                        $options[(string) $i] = sprintf('Column #%d', $i);
                    }
                }

                return [
                    Placeholder::make('preview')
                        ->label('First 10 rows')
                        ->content(view('filament.previews.csv-preview', ['rows' => $rows])),
                    Select::make('sku_column_index')
                        ->label('SKU column')
                        ->options($options)
                        ->required(),
                    Select::make('price_column_index')
                        ->label('Price column')
                        ->options($options)
                        ->required(),
                    Radio::make('decimal_format')
                        ->options([
                            CompetitorCsvMapping::FORMAT_DOT => 'Dot decimal (1,234.56)',
                            CompetitorCsvMapping::FORMAT_COMMA => 'Comma decimal (1.234,56)',
                        ])
                        ->default(CompetitorCsvMapping::FORMAT_DOT)
                        ->required(),
                ];
            })
            ->action(function (CsvParseError $record, array $data): void {
                if ($record->competitor_id === null) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot resolve: no competitor attached')
                        ->body('This row has no competitor_id — rename the file to match {competitor_slug}_{YYYY-MM-DD}.csv first.')
                        ->send();

                    return;
                }

                $competitor = Competitor::findOrFail($record->competitor_id);

                CompetitorCsvMapping::updateOrCreate(
                    ['competitor_id' => $competitor->id],
                    [
                        'sku_column_index' => (int) $data['sku_column_index'],
                        'price_column_index' => (int) $data['price_column_index'],
                        'decimal_format' => (string) $data['decimal_format'],
                        'detected_at' => now(),
                    ]
                );

                $src = self::locateQuarantinedFile($record->filename);
                $incomingDir = storage_path('app/competitors/incoming');
                if (! is_dir($incomingDir)) {
                    @mkdir($incomingDir, 0o775, true);
                }
                $dest = $incomingDir.DIRECTORY_SEPARATOR.basename($record->filename);

                if ($src !== null && is_file($src)) {
                    @rename($src, $dest);
                }

                // Re-dispatch the ingest pipeline. CompetitorWatchCommand would
                // pick this up on its next 5-minute tick, but dispatching
                // immediately gives the operator instant feedback.
                IngestCompetitorCsvJob::dispatch($dest, (int) $competitor->id)
                    ->onQueue('competitor-csv');

                $record->update(['resolved_at' => now()]);

                Notification::make()
                    ->success()
                    ->title('Mapping saved; CSV re-queued for ingest')
                    ->body(sprintf('File moved to incoming/ — %s will reprocess on the next tick.', $record->filename))
                    ->send();
            });
    }

    /**
     * Build the current-tab query over csv_parse_errors.
     */
    protected function scopedQuery(): Builder
    {
        $q = CsvParseError::query()->with(['competitor'])->whereNull('resolved_at');

        return match ($this->activeTab) {
            'quarantine' => $q->where('issue_type', CsvParseError::TYPE_AMBIGUOUS_MAPPING),
            'orphans' => $q->where('issue_type', CsvParseError::TYPE_ORPHAN_SKU),
            'encoding' => $q->where('issue_type', CsvParseError::TYPE_ENCODING_FAILURE),
            'values' => $q->whereIn('issue_type', [
                CsvParseError::TYPE_UNPARSEABLE_PRICE,
                CsvParseError::TYPE_INVALID_SKU_FORMAT,
                CsvParseError::TYPE_INVALID_FILENAME,
            ]),
            default => $q->whereRaw('0 = 1'),
        };
    }

    /**
     * Locate a quarantined CSV — Phase 5 Plan 02 writes to date-subdirectories
     * (storage/app/competitors/quarantine/{Y-m-d}/{filename}); ad-hoc drops or
     * the demo seeder may land under the top-level quarantine/ path.
     */
    public static function locateQuarantinedFile(string $filename): ?string
    {
        $name = basename($filename);

        $flat = storage_path('app/competitors/quarantine/'.$name);
        if (is_file($flat)) {
            return $flat;
        }

        $base = storage_path('app/competitors/quarantine');
        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $candidate = $base.DIRECTORY_SEPARATOR.$entry.DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function render(): View
    {
        return view(static::$view);
    }
}
