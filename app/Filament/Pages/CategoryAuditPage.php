<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\CategoryAuditFinding;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260607-t6w — Category Audit page.
 *
 * /admin/category-audit — operator-facing UI surfacing the latest
 * AuditProductCategoriesCommand snapshot. The page shows misclassified live
 * products grouped by issue type (missing / orphaned / uncategorized /
 * suspicious brand-category mismatch) so the ecom manager can triage on
 * Monday morning after the Fri 22:00 London cron run.
 *
 * Per-row action 'Run Claude category review' invokes the existing
 * products:assign-taxonomy command for a single SKU (~1p Claude spend);
 * bulk action does the same for a CSV of selected SKUs. Both gated
 * admin-only via ->visible() + ->authorize() because they cost real money.
 *
 * Query scopes to the LATEST run via a subquery on run_id — without this
 * the table would surface every historical row (the TRUNCATE in the
 * audit command keeps the table small, but the predicate is the contract
 * and guards against accidental table growth from manual inserts).
 *
 * RBAC: admin + pricing_manager (page-level). Claude-spending actions are
 * tighter — admin only.
 *
 * navigationSort=15 — sits between ProductResource (10) and QuoteResource
 * (20) in the Catalogue group. PriceHistoryPage at 70 stays last; sort
 * numbers verified 2026-06-07 via grep.
 */
final class CategoryAuditPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'category-audit';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Category Audit';

    /**
     * Catalogue group sort order (verified 2026-06-07):
     *   ProductResource=10, [this]=15, QuoteResource=20, PriceHistoryPage=70.
     */
    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.category-audit';

    protected static ?string $title = 'Category Audit';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->defaultSort('severity', 'asc')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('sku')
                    ->fontFamily('mono')
                    ->copyable()
                    ->sortable()
                    ->searchable()
                    ->url(fn (CategoryAuditFinding $r): string => "/admin/products/{$r->product_id}/edit"),

                TextColumn::make('product.name')
                    ->label('Name')
                    ->limit(60)
                    ->tooltip(fn (CategoryAuditFinding $r): ?string => $r->product?->name),

                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('category_name')
                    ->label('Category')
                    ->tooltip(fn (CategoryAuditFinding $r): ?string => $r->category_root_name !== null
                        ? "Root: {$r->category_root_name}"
                        : null),

                TextColumn::make('issue_type')
                    ->label('Issue')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'missing' => 'danger',
                        'orphaned' => 'warning',
                        'uncategorized' => 'warning',
                        'suspicious' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('audited_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                SelectFilter::make('issue_type')
                    ->options([
                        'missing' => 'Missing category',
                        'orphaned' => 'Orphaned category',
                        'uncategorized' => 'Uncategorized',
                        'suspicious' => 'Suspicious brand-category mismatch',
                    ]),

                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->multiple()
                    ->options($this->getBrandOptions()),
            ])
            ->actions([
                Action::make('run_claude_review')
                    ->label('Run Claude category review')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CategoryAuditFinding $r): string => "Run Claude review for {$r->sku}")
                    ->modalDescription('~1p Claude spend. Calls products:assign-taxonomy to suggest the right category. Updates the product\'s category_id directly on success.')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->action(function (CategoryAuditFinding $record): void {
                        try {
                            Log::info('CategoryAuditPage: claude-review invoked', [
                                'sku' => $record->sku,
                                'actor_id' => auth()->id(),
                            ]);
                            // Array option form — never concatenate user input
                            // into a shell-style string (mirror AutoCreateHealthPage:281).
                            Artisan::call('products:assign-taxonomy', ['--skus' => $record->sku]);

                            Notification::make()
                                ->success()
                                ->title("Claude review dispatched for {$record->sku}")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Claude review failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulk_claude_review')
                    ->label('Run Claude review (selected)')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Bulk Claude category review')
                    ->modalDescription(fn (EloquentCollection $records): string => 'About to spend ~£'
                        .number_format($records->count() / 100, 2)
                        .' on Claude across '
                        .$records->count()
                        .' selected SKU(s) (~1p per SKU). Proceed?')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->action(function (EloquentCollection $records): void {
                        $skus = $records->pluck('sku')->filter()->unique()->values()->implode(',');
                        try {
                            Artisan::call('products:assign-taxonomy', ['--skus' => $skus]);

                            Notification::make()
                                ->success()
                                ->title('Claude review dispatched for '.$records->count().' SKU(s)')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Bulk Claude review failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * Scope the table to the LATEST run only — the run with the most recent
     * audited_at across all rows. Without this filter the page would surface
     * every historical row (the audit command's TRUNCATE keeps the table
     * small in practice, but the predicate is the contract).
     *
     * The latest run_id is resolved as a SCALAR first via value(), then applied
     * as an equality predicate. We deliberately do NOT use an
     * IN(subquery ORDER BY ... LIMIT 1) scope on run_id: MariaDB (prod) rejects
     * IN(subquery + LIMIT) with SQLSTATE 42000 error 1235
     * ("doesn't yet support 'LIMIT & IN/ALL/ANY/SOME subquery'"), which 500'd
     * Filament's pagination count query on every render. SQLite permitted the
     * old subquery form, which is why this was invisible to the test suite.
     * whereRaw('1 = 0') guards the empty-table case so an empty table still
     * renders zero rows without error.
     */
    private function buildQuery(): Builder
    {
        $latestRunId = CategoryAuditFinding::query()
            ->orderByDesc('audited_at')
            ->value('run_id');

        return CategoryAuditFinding::query()
            ->with('product:id,name')
            ->when(
                $latestRunId !== null,
                fn (Builder $q): Builder => $q->where('run_id', $latestRunId),
                fn (Builder $q): Builder => $q->whereRaw('1 = 0'),
            );
    }

    /**
     * Brand-id → name map for the SelectFilter. Alpha-sorted (case-insensitive)
     * for the picker — mirror AdCandidatesPage::getBrandOptions().
     *
     * @return array<int, string>
     */
    public function getBrandOptions(): array
    {
        /** @var TaxonomyResolver $taxonomy */
        $taxonomy = app(TaxonomyResolver::class);
        $out = [];
        foreach ($taxonomy->allBrands() as $term) {
            $id = (int) ($term['id'] ?? 0);
            $name = (string) ($term['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $out[$id] = $name;
            }
        }
        uasort($out, fn (string $a, string $b): int => strcasecmp($a, $b));

        return $out;
    }

    /**
     * Footer summary banner data — per-issue counts + last-run + next-run hint.
     *
     * @return array{
     *     total:int,
     *     missing:int,
     *     orphaned:int,
     *     uncategorized:int,
     *     suspicious:int,
     *     last_run_at:?string,
     *     next_run_hint:string
     * }
     */
    public function getSummary(): array
    {
        $base = CategoryAuditFinding::query();
        $lastRunAt = $base->clone()->max('audited_at');

        return [
            'total' => (int) $base->clone()->count(),
            'missing' => (int) $base->clone()->where('issue_type', 'missing')->count(),
            'orphaned' => (int) $base->clone()->where('issue_type', 'orphaned')->count(),
            'uncategorized' => (int) $base->clone()->where('issue_type', 'uncategorized')->count(),
            'suspicious' => (int) $base->clone()->where('issue_type', 'suspicious')->count(),
            'last_run_at' => $lastRunAt !== null
                ? Carbon::parse($lastRunAt)->toIso8601String()
                : null,
            'next_run_hint' => 'Fri 22:00 London',
        ];
    }
}
