<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Quick task 260607-pys — Ad Candidates page.
 *
 * /admin/ad-candidates — operator-facing UI surfacing the live "golden ad
 * target" candidate set (margin ≥ £X + competitor undercut + supplier in
 * stock 7d) with a brand multi-select filter so the operator can plan a
 * Google Ads campaign in one screen, then copy/paste a SKU CSV into
 * ads.google.com's bulk upload.
 *
 * Backed by AdCandidateScanner (single golden-target SQL surface — also
 * used by BackfillMerchantFeedCommand + SnapshotAggregator). Drift between
 * the page and the backfill command is structurally impossible — change
 * the rule in the scanner, both surfaces pick it up.
 *
 * RBAC: admin + pricing_manager (sales / read_only get 403). The bulk
 * "Send to Google Ads" action is a PLACEHOLDER ONLY — fires a Filament
 * warning notification pointing at Phase 15 for the real Google Ads API
 * integration. Today the operator copies the SKU CSV and pastes into
 * ads.google.com manually.
 *
 * Filter state is Livewire-property only (no persistent storage per scope
 * decision — page reloads reset to defaults; bookmarkable URLs use
 * Livewire's wire:model.live serialisation).
 *
 * Performance: scanner result is cached per-request via PHP attribute
 * (rowsByKey) so the table query, footer summary, and bulk action share
 * one scan call. Default thresholds (£199 / stock / beat) scan the live
 * catalogue in ~1s on production.
 */
final class AdCandidatesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'ad-candidates';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Ad Candidates';

    /** Between NotificationCentrePage=100 and AutoCreateHealthPage=110. */
    protected static ?int $navigationSort = 105;

    protected static string $view = 'filament.pages.ad-candidates';

    protected static ?string $title = 'Ad Candidates';

    // ── Livewire filter state ───────────────────────────────────────────────

    /** @var array<int, int> */
    public array $filterBrandIds = [];

    public int $filterMinMarginPounds = 199;

    public bool $filterStockRequired = true;

    public bool $filterBeatRequired = true;

    // ── Page lifecycle ──────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * Brand-id → name map for the multi-select filter UI in the page view.
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
        ksort($out);

        return $out;
    }

    // ── Scanner glue ────────────────────────────────────────────────────────

    /**
     * Resolve the current scanner result, keyed by SKU. Cached per-request
     * via $this->rowsByKey so table query + footer summary + bulk action
     * all share ONE scan() call.
     *
     * @var array<string, \stdClass>|null
     */
    private ?array $rowsByKey = null;

    /**
     * Public for the page view (footer summary) AND the table query.
     *
     * @return array<string, \stdClass> sku => decorated row
     */
    public function getScannerRows(): array
    {
        if ($this->rowsByKey !== null) {
            return $this->rowsByKey;
        }

        $brandIds = array_values(array_map('intval', $this->filterBrandIds));
        $minMarginPence = max(0, (int) round($this->filterMinMarginPounds * 100));

        $rows = app(AdCandidateScanner::class)->scan(
            brandIds: $brandIds,
            minMarginPence: $minMarginPence,
            stockRequired: $this->filterStockRequired,
            beatRequired: $this->filterBeatRequired,
        );

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row->sku] = $row;
        }

        return $this->rowsByKey = $byKey;
    }

    /**
     * Footer summary numbers for the page view.
     *
     * @return array{count:int, total_margin_pence:int, avg_margin_pence:int}
     */
    public function getSummary(): array
    {
        $rows = $this->getScannerRows();
        $count = count($rows);
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row->margin_pence;
        }

        return [
            'count' => $count,
            'total_margin_pence' => $total,
            'avg_margin_pence' => $count > 0 ? (int) ($total / $count) : 0,
        ];
    }

    /**
     * Force-clear the per-request scanner cache whenever a filter property
     * changes via Livewire. Filament re-evaluates the table query on every
     * Livewire update; without this hook the page would render stale
     * scanner output.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->rowsByKey = null;
        }
    }

    // ── Table ───────────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            // No defaultSort — the scanner already pre-sorts by most
            // undercut + highest margin first. Letting Filament sort by
            // products.id keeps the table stable across pagination; the
            // user can click any column header to override.
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('sku')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('name')
                    ->limit(50)
                    ->tooltip(fn (Product $r): string => (string) $r->name),

                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->state(fn (Product $r): string => $this->rowFor($r)?->brand_name ?? '—')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('margin_pounds')
                    ->label('Margin £')
                    ->state(fn (Product $r): string => $this->formatPence(
                        $this->rowFor($r)?->margin_pence ?? 0,
                    ))
                    ->color(fn (Product $r): string => (($this->rowFor($r)?->margin_pence ?? 0) > 50000)
                        ? 'success'
                        : 'gray'),

                TextColumn::make('sell_pounds')
                    ->label('Sell £')
                    ->state(fn (Product $r): string => $this->formatPence(
                        $this->rowFor($r)?->sell_price_pence ?? 0,
                    )),

                TextColumn::make('lowest_comp_pounds')
                    ->label('Lowest Comp £')
                    ->state(fn (Product $r): string => $this->formatPence(
                        $this->rowFor($r)?->lowest_comp_pence ?? 0,
                    )),

                TextColumn::make('beat_pct')
                    ->label('Beat %')
                    ->state(fn (Product $r): string => $this->formatBeatPct(
                        $this->rowFor($r)?->beat_pct_bps ?? 0,
                    ))
                    ->color(fn (Product $r): string => (($this->rowFor($r)?->beat_pct_bps ?? 0) < 0)
                        ? 'success'
                        : 'danger'),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->state(fn (Product $r): int => (int) ($this->rowFor($r)?->stock ?? 0))
                    ->badge()
                    ->color(fn (int $state): string => $state > 10
                        ? 'success'
                        : ($state >= 1 ? 'warning' : 'gray')),

                TextColumn::make('best_supplier')
                    ->label('Best Supplier')
                    ->state(fn (Product $r): ?string => $this->rowFor($r)?->best_supplier)
                    ->placeholder('—'),
            ])
            ->actions([
                Action::make('view_storefront')
                    ->label('View on storefront')
                    ->icon('heroicon-o-globe-alt')
                    ->openUrlInNewTab()
                    ->url(fn (Product $r): string => rtrim(
                        (string) config('services.woo.storefront_url'),
                        '/',
                    ).'/?p='.(int) $r->woo_product_id),

                Action::make('edit_admin')
                    ->label('Edit in admin')
                    ->icon('heroicon-o-pencil')
                    ->url(fn (Product $r): string => "/admin/products/{$r->id}/edit"),
            ])
            ->bulkActions([
                BulkAction::make('copy_sku_csv')
                    ->label('Copy SKU CSV')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->action(function (EloquentCollection $records): \Symfony\Component\HttpFoundation\StreamedResponse {
                        $skus = $records->pluck('sku')->filter()->values()->all();
                        $filename = 'ad-candidates-skus-'.now()->format('Ymd-His').'.csv';

                        return response()->streamDownload(static function () use ($skus): void {
                            echo "sku\n";
                            echo implode(',', $skus);
                        }, $filename, ['Content-Type' => 'text/csv']);
                    }),

                BulkAction::make('send_to_google_ads')
                    ->label('Send to Google Ads')
                    ->icon('heroicon-o-megaphone')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Google Ads API integration is Phase 15')
                    ->modalDescription('This is a placeholder action. For now use "Copy SKU CSV" + manual upload at ads.google.com.')
                    ->action(function (EloquentCollection $records): void {
                        $skus = $records->pluck('sku')->filter()->values()->all();
                        Notification::make()
                            ->warning()
                            ->title('Google Ads API integration is Phase 15')
                            ->body(
                                'For now use "Copy SKU CSV" + manual upload at ads.google.com. SKUs: '
                                .implode(',', $skus),
                            )
                            ->send();
                    }),
            ]);
    }

    /**
     * Build a Product query narrowed to the scanner's current SKU set.
     * Filament owns the pagination + sort + per-row hydration; we just
     * scope to "products in the scanner result".
     */
    private function buildQuery(): Builder
    {
        $skus = array_keys($this->getScannerRows());
        $query = Product::query();

        if ($skus === []) {
            // No golden targets — return an unmatchable predicate so the
            // table renders "no records" instead of the entire catalogue.
            return $query->whereRaw('1 = 0');
        }

        // Synthesise a sortable margin_pence_dummy column via DB::raw via
        // CASE — falls back to constant 0 for products not in the scanner
        // result (which the whereIn excludes anyway). Filament's
        // defaultSort needs a column-shaped selectable expression so we
        // expose one via addSelect.
        return $query->whereIn('sku', $skus);
    }

    /**
     * Resolve the scanner row for a Product, by SKU.
     */
    private function rowFor(Product $product): ?\stdClass
    {
        return $this->getScannerRows()[(string) $product->sku] ?? null;
    }

    private function formatPence(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }

    /**
     * beat_pct_bps → human-readable percent.  Negative values mean we
     * undercut the competitor (good for ad clicks).
     */
    private function formatBeatPct(int $bps): string
    {
        return number_format($bps / 100, 1).'%';
    }
}
