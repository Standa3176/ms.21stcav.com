<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\Products\Models\Product;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 6 Plan 04 — Auto-Create Review Resource (AUTO-06, D-09, D-06).
 *
 * Admin + pricing_manager browse auto-created drafts awaiting triage. The
 * Resource binds to the existing Product model but scopes the query to the
 * auto-create statuses only — `manual`-status rows (Phase 2-synced products)
 * are excluded so the review inbox stays focused.
 *
 * Columns (default sort = completeness_score DESC — closest-to-ready first):
 *   - SKU (searchable)
 *   - name (searchable, limit 60)
 *   - thumbnail (image_url via ImageColumn)
 *   - auto_create_status badge
 *   - completeness_score colour-coded badge (red <50 / amber 50-84 / green 85+)
 *   - brand.name + category.name (forward-compat: sometimes null for
 *     needs_brand_or_category_assignment rows)
 *   - requires_manual_image_review icon
 *   - created_at relative
 *
 * Row actions:
 *   - Approve → PublishProductJob::dispatch; confirmation modal lists
 *     completeness_missing_fields when score < publish_threshold (D-09).
 *     Override reason captured in audit log via spatie/activitylog.
 *   - Reject → 8-enum reason picker + free-text note (mandatory for 'other')
 *     → writes AutoCreateRejection row + flips Product.auto_create_status
 *     to 'rejected'.
 *   - Quick-Edit → modal form for name / short / long / meta descriptions.
 *
 * Bulk actions:
 *   - approve-selected: silently skips rows below threshold (D-09 bulk rule) +
 *     toast reports skipped count.
 *   - reject-with-reason: one reason applied to all selected.
 *   - bulk-set-category / bulk-set-brand: mass taxonomy triage for the
 *     needs_brand_or_category_assignment bucket.
 *
 * Every action chains `->authorize('update', $record)` as Warning 9
 * defence-in-depth on top of ProductPolicy.
 *
 * RBAC: ProductPolicy (hand-written, Phase 2). admin + pricing_manager can
 * approve/reject/edit; sales + read_only can view only.
 */
class AutoCreateReviewResource extends Resource
{
    use HasExportableTable;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    // Quick task 260504-ev5 — 8-group nav restructure. Auto-Create Review
    // remains in Review group at sort 10 (first item — primary triage inbox).
    protected static ?string $navigationGroup = 'Review';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Auto-Create Review';

    protected static ?string $modelLabel = 'Auto-Create Draft';

    protected static ?string $pluralModelLabel = 'Auto-Create Drafts';

    protected static ?string $slug = 'auto-create-reviews';

    /** Review inbox statuses — excludes `manual` (Phase 2 synced rows) + terminal states. */
    public const REVIEW_STATUSES = [
        'draft',
        'pending_review',
        'needs_brand_or_category_assignment',
    ];

    /**
     * Scope the table + edit page to review-inbox statuses only, eager-loading
     * relations rendered in the table to prevent N+1 (Pitfall 10).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('auto_create_status', self::REVIEW_STATUSES);
    }

    /**
     * Quick task 260504-ev5 — warning badge for drafts awaiting review.
     * Re-uses the same REVIEW_STATUSES set the table query scopes by, so
     * the badge count and the table row count always agree.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()
            ->whereIn('auto_create_status', self::REVIEW_STATUSES)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        // Edit page reuses a subset of the ProductResource form + adds
        // the Phase 6 auto-create-specific fields. Quick-edit in the table
        // provides the fast-path; this form is the full-fidelity editor.
        return $form->schema([
            TextInput::make('sku')->disabled(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255),
            Textarea::make('short_description')->rows(4)->maxLength(5000),
            Textarea::make('long_description')->rows(8)->maxLength(20000),
            TextInput::make('meta_description')->maxLength(255),
            Select::make('auto_create_status')->options([
                'draft' => 'Draft',
                'pending_review' => 'Pending review',
                'needs_brand_or_category_assignment' => 'Needs brand/category',
                'approved' => 'Approved',
                'published' => 'Published',
                'rejected' => 'Rejected',
            ])->disabled(),
            TextInput::make('completeness_score')->disabled()->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (Product $r) => $r->name),
                ImageColumn::make('image_url')
                    ->label('Image')
                    ->size(48)
                    ->circular(false)
                    ->defaultImageUrl(fn () => (string) config('product_auto_create.placeholder_image_url')),
                TextColumn::make('auto_create_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending_review' => 'warning',
                        'needs_brand_or_category_assignment' => 'danger',
                        'approved' => 'info',
                        'published' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('completeness_score')
                    ->label('Score')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 50 => 'danger',
                        $state < 85 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : $state.'/100')
                    ->sortable(),
                TextColumn::make('brand_id')
                    ->label('Brand ID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category_id')
                    ->label('Category ID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requires_manual_image_review')
                    ->label('Image review?')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('completeness_score', 'desc')
            ->filters([
                SelectFilter::make('auto_create_status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'draft' => 'Draft',
                        'pending_review' => 'Pending review',
                        'needs_brand_or_category_assignment' => 'Needs brand/category',
                    ]),
                SelectFilter::make('brand_id')->label('Brand'),
                SelectFilter::make('category_id')->label('Category'),
                TernaryFilter::make('requires_manual_image_review')
                    ->label('Image review flag'),
                Filter::make('completeness_tier')
                    ->form([
                        Select::make('tier')->options([
                            'red' => 'Red (<50)',
                            'amber' => 'Amber (50-84)',
                            'green' => 'Green (85+)',
                        ]),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return match ($data['tier'] ?? null) {
                            'red' => $q->where('completeness_score', '<', 50),
                            'amber' => $q->whereBetween('completeness_score', [50, 84]),
                            'green' => $q->where('completeness_score', '>=', 85),
                            default => $q,
                        };
                    }),
            ])
            ->actions([
                // ── Approve (D-09 publish gate) ────────────────────────────
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Product $r) => 'Publish '.$r->sku)
                    ->modalDescription(function (Product $r): string {
                        $threshold = (int) config('product_auto_create.completeness_publish_threshold', 85);
                        if (($r->completeness_score ?? 0) >= $threshold) {
                            return 'Dispatches PublishProductJob. Product status flips to "publish" + Woo product published.';
                        }
                        $missing = is_array($r->completeness_missing_fields)
                            ? implode(', ', $r->completeness_missing_fields)
                            : 'unknown';

                        return sprintf(
                            'Score %d/100 is below the publish threshold (%d). Missing: %s. Override reason is required and captured in the audit log.',
                            (int) ($r->completeness_score ?? 0),
                            $threshold,
                            $missing,
                        );
                    })
                    ->form(function (Product $r): array {
                        $threshold = (int) config('product_auto_create.completeness_publish_threshold', 85);
                        if (($r->completeness_score ?? 0) >= $threshold) {
                            return [];
                        }

                        return [
                            Textarea::make('override_reason')
                                ->label('Override reason (required — below publish threshold)')
                                ->required()
                                ->maxLength(2000),
                        ];
                    })
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (Product $record, array $data): void {
                        $threshold = (int) config('product_auto_create.completeness_publish_threshold', 85);
                        $score = (int) ($record->completeness_score ?? 0);

                        if ($score < $threshold) {
                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->withProperties([
                                    'override_reason' => $data['override_reason'] ?? '',
                                    'score' => $score,
                                    'threshold' => $threshold,
                                    'missing_fields' => $record->completeness_missing_fields,
                                ])
                                ->log('auto_create.publish.low_completeness_override');
                        }

                        PublishProductJob::dispatch((int) $record->id, (int) auth()->id());

                        Notification::make()
                            ->success()
                            ->title('Publish dispatched')
                            ->body('Check Woo + Horizon after a few seconds.')
                            ->send();
                    }),

                // ── Reject (D-06 enum + structured audit) ──────────────────
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('reason')
                            ->label('Reason')
                            ->required()
                            ->options(self::rejectionReasonOptions()),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(2000)
                            ->required(fn (\Filament\Forms\Get $get) => $get('reason') === AutoCreateRejection::REASON_OTHER),
                    ])
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (Product $record, array $data): void {
                        AutoCreateRejection::create([
                            'product_id' => $record->id,
                            'reason' => $data['reason'],
                            'notes' => $data['notes'] ?? null,
                            'rejected_by_user_id' => auth()->id(),
                        ]);

                        $record->forceFill(['auto_create_status' => 'rejected'])->saveQuietly();

                        Notification::make()
                            ->success()
                            ->title('Draft rejected')
                            ->body('Rejection logged; product status set to "rejected".')
                            ->send();
                    }),

                // ── Quick-edit (modal — fast-path for narrow edits) ────────
                Action::make('quick_edit')
                    ->label('Quick edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record) ?? false)
                    ->form([
                        TextInput::make('name')->required()->maxLength(255),
                        Textarea::make('short_description')->rows(3)->maxLength(5000),
                        Textarea::make('long_description')->rows(6)->maxLength(20000),
                        TextInput::make('meta_description')->maxLength(255),
                    ])
                    ->fillForm(fn (Product $r): array => [
                        'name' => $r->name,
                        'short_description' => $r->short_description,
                        'long_description' => $r->long_description,
                        'meta_description' => $r->meta_description,
                    ])
                    ->action(function (Product $record, array $data): void {
                        $record->update([
                            'name' => $data['name'],
                            'short_description' => $data['short_description'] ?? null,
                            'long_description' => $data['long_description'] ?? null,
                            'meta_description' => $data['meta_description'] ?? null,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Draft updated')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // ── Bulk approve (D-09 silent-skip rule) ───────────────
                    BulkAction::make('approve_selected')
                        ->label('Approve selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                        ->action(function (Collection $records): void {
                            $threshold = (int) config('product_auto_create.completeness_publish_threshold', 85);
                            $approved = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Product $record */
                                if (! auth()->user()?->can('update', $record)) {
                                    continue;
                                }
                                $score = (int) ($record->completeness_score ?? 0);
                                if ($score < $threshold) {
                                    $skipped++;

                                    continue;
                                }
                                PublishProductJob::dispatch((int) $record->id, (int) auth()->id());
                                $approved++;
                            }

                            Notification::make()
                                ->success()
                                ->title("Approved {$approved} / skipped {$skipped} below threshold")
                                ->body('Bulk publish respects the completeness threshold — use single-row Approve with override reason for below-threshold publishes.')
                                ->send();
                        }),

                    // ── Bulk reject with reason ────────────────────────────
                    BulkAction::make('reject_with_reason')
                        ->label('Reject selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                        ->form([
                            Select::make('reason')
                                ->label('Reason')
                                ->required()
                                ->options(self::rejectionReasonOptions()),
                            Textarea::make('notes')
                                ->rows(3)
                                ->maxLength(2000)
                                ->required(fn (\Filament\Forms\Get $get) => $get('reason') === AutoCreateRejection::REASON_OTHER),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = 0;
                            foreach ($records as $record) {
                                /** @var Product $record */
                                if (! auth()->user()?->can('update', $record)) {
                                    continue;
                                }
                                AutoCreateRejection::create([
                                    'product_id' => $record->id,
                                    'reason' => $data['reason'],
                                    'notes' => $data['notes'] ?? null,
                                    'rejected_by_user_id' => auth()->id(),
                                ]);
                                $record->forceFill(['auto_create_status' => 'rejected'])->saveQuietly();
                                $count++;
                            }

                            Notification::make()
                                ->success()
                                ->title("Rejected {$count} drafts")
                                ->send();
                        }),

                    // ── Bulk set category ─────────────────────────────────
                    BulkAction::make('bulk_set_category')
                        ->label('Set category')
                        ->icon('heroicon-o-folder')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                        ->form([
                            TextInput::make('category_id')
                                ->label('Category ID')
                                ->required()
                                ->numeric(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = 0;
                            foreach ($records as $record) {
                                /** @var Product $record */
                                if (! auth()->user()?->can('update', $record)) {
                                    continue;
                                }
                                $record->forceFill(['category_id' => (int) $data['category_id']])->saveQuietly();
                                $count++;
                            }
                            Notification::make()
                                ->success()
                                ->title("Updated category on {$count} drafts")
                                ->send();
                        }),

                    // ── Bulk set brand ────────────────────────────────────
                    BulkAction::make('bulk_set_brand')
                        ->label('Set brand')
                        ->icon('heroicon-o-tag')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)
                        ->form([
                            TextInput::make('brand_id')
                                ->label('Brand ID')
                                ->required()
                                ->numeric(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = 0;
                            foreach ($records as $record) {
                                /** @var Product $record */
                                if (! auth()->user()?->can('update', $record)) {
                                    continue;
                                }
                                $record->forceFill(['brand_id' => (int) $data['brand_id']])->saveQuietly();
                                $count++;
                            }
                            Notification::make()
                                ->success()
                                ->title("Updated brand on {$count} drafts")
                                ->send();
                        }),
                ]),
                // Phase 7 Plan 03 — DASH-04 CSV export (inline <10k + queued 10k-100k).
                // Kept OUTSIDE the BulkActionGroup so the export appears as a
                // first-class bulk action rather than buried in the approve/reject menu.
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ])
            // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
            ->headerActions([
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            ->emptyStateHeading('No products awaiting review')
            ->emptyStateDescription('Auto-create drafts appear here when the supplier sync detects new SKUs. Configure skip rules to filter out unwanted drafts before they reach the inbox.')
            ->emptyStateIcon('heroicon-o-inbox');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutoCreateReview::route('/'),
            'edit' => Pages\EditAutoCreateReview::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Products are created by CreateWooProductJob — not from UI.
        return false;
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────
    //
    // Scoped to review-inbox statuses via getEloquentQuery above, so global
    // search results here show ONLY auto-create drafts (not already-published
    // products — those come from ProductResource).

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Product $record */
        return ($record->sku ?? '—').' · '.($record->name ?? '(no name)');
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Product $record */
        return [
            'Status' => $record->auto_create_status ?? '—',
            'Completeness' => $record->completeness_score !== null
                ? $record->completeness_score.'/100'
                : '—',
            'Image review' => $record->requires_manual_image_review ? 'Yes' : 'No',
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('edit', ['record' => $record]);
    }

    /** @return array<string, string> */
    private static function rejectionReasonOptions(): array
    {
        return [
            AutoCreateRejection::REASON_NOT_A_REAL_PRODUCT => 'Not a real product',
            AutoCreateRejection::REASON_DUPLICATE_OF_EXISTING => 'Duplicate of existing',
            AutoCreateRejection::REASON_DISCONTINUED_BY_SUPPLIER => 'Discontinued by supplier',
            AutoCreateRejection::REASON_SPARE_PART_OR_ACCESSORY => 'Spare part or accessory',
            AutoCreateRejection::REASON_POOR_QUALITY_DATA => 'Poor quality data',
            AutoCreateRejection::REASON_MISCLASSIFIED_BRAND_OR_CATEGORY => 'Misclassified brand or category',
            AutoCreateRejection::REASON_BELOW_VIABILITY_THRESHOLD => 'Below viability threshold',
            AutoCreateRejection::REASON_OTHER => 'Other (requires notes)',
        ];
    }
}
