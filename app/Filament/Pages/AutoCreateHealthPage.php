<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Products\Models\Product;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260606-mx9 — Auto-create local-state drift inspector.
 *
 * 2026-06-06 Manhattan incident: Sony VPL-EX235 (sku MANH-VPL) was
 * created locally with the auto-create pipeline but its Woo product
 * was missing gallery images — the operator had no internal signal
 * for "local data populated, downstream sync drifted." Browsing
 * /wp-admin caught it; we want every drift shape surfaced inside
 * the Filament chrome instead.
 *
 * This page lists pipeline-created products (auto_create_status !=
 * 'manual') that are missing ANY of:
 *   - gallery_image_urls (empty or NULL)
 *   - brand_id (NULL)
 *   - category_id (NULL)
 *   - woo_product_id (NULL — never pushed)
 *
 * Per-row actions Resync-to-Woo and Source-images dispatch the
 * existing artisan commands via Artisan::call (sync, in-request).
 *
 * Admin-only — tighter than NotificationCentrePage's page-level
 * gate because both per-row actions can cost real money (Claude
 * spend on source-images; Woo writes on resync).
 *
 * Manhattan-shape drift (local images present, Woo placeholder)
 * is NOT covered by this page — those rows have `woo_product_id`
 * set and all local fields populated, so the predicate misses
 * them. Follow-up: periodic Woo image-count diff scan.
 *
 * Driver-aware JSON expression mirrors PruneOrphanSuggestionsCommand
 * (commit d6c8a4d) so Pest's in-memory SQLite hits json_array_length
 * while production MySQL hits JSON_LENGTH. The expression is a
 * compile-time literal (driver name is from getDriverName(), not
 * user input) so there is no SQLi vector — see threat model
 * T-mx9-03.
 *
 * Plan-vs-schema deviation: PLAN.md called the legacy-WC exclusion
 * `auto_create_status IS NOT NULL`, but the column is NOT NULL
 * DEFAULT 'manual' per migration 2026_04_22_100300. We use
 * `auto_create_status != 'manual'` instead, which preserves the
 * intended scope (manual is the legacy / pre-auto-create marker
 * per the same migration's docblock). Tracked in test file's
 * file-level deviation note.
 */
final class AutoCreateHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'auto-create-health';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Auto-create Health';

    /** After NotificationCentrePage (100) — sits second in the Operations group. */
    protected static ?int $navigationSort = 110;

    protected static string $view = 'filament.pages.auto-create-health';

    /**
     * Admin-only page gate. Both per-row actions (Resync to Woo,
     * Source images) are real-money operations — Claude spend + Woo
     * writes — so we tighten access vs NotificationCentrePage's
     * view-for-all + actions-per-role pattern. See T-mx9-01.
     */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return (bool) $user?->hasRole('admin');
    }

    /**
     * Sidebar attention badge — unhealthy count in 'warning' tone.
     *
     * Wrapped in try/catch returning null on Throwable (mirror
     * SuggestionResource:65 precedent) — the badge runs on every
     * sidebar render, a broken query (table dropped, JSON driver
     * mismatch on a future DB change) MUST NOT 500 the entire admin
     * chrome. See threat model T-mx9-02.
     *
     * Hides at 0 — operator only sees the badge when there is
     * actionable drift.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = static::unhealthyQuery()->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Three-tier per-issue breakdown surfaced on badge hover. 60s
     * Cache::remember keeps the four COUNT queries off the hot
     * sidebar render path (Filament invokes this on every page
     * load — without the cache, each load runs 4 queries against
     * the products table).
     *
     * Same defensive try/catch as the badge — a broken query yields
     * null (tooltip omitted) rather than blowing up the admin
     * chrome.
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        try {
            return Cache::remember('auto_create_health.nav_breakdown', 60, static function (): string {
                $emptyImagesExpr = static::emptyImagesExpr();

                $base = Product::query()->where('auto_create_status', '!=', 'manual');

                $noImages = (clone $base)
                    ->where(static fn (Builder $q): Builder => $q->whereNull('gallery_image_urls')
                        ->orWhereRaw($emptyImagesExpr))
                    ->count();
                $noBrand = (clone $base)->whereNull('brand_id')->count();
                $noCategory = (clone $base)->whereNull('category_id')->count();
                $noWoo = (clone $base)->whereNull('woo_product_id')->count();

                return sprintf(
                    '%s no images • %s no brand • %s no category • %s no Woo',
                    number_format($noImages),
                    number_format($noBrand),
                    number_format($noCategory),
                    number_format($noWoo),
                );
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Public so Pest can assert the predicate directly without
     * going through Livewire's table rendering path. The table()
     * method delegates to this so badge count, sidebar tile, and
     * the table view share one definition (drift-prevention).
     */
    public function getUnhealthyQuery(): Builder
    {
        return static::unhealthyQuery();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getUnhealthyQuery())
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('sku')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('—'),

                TextColumn::make('name')
                    ->limit(50)
                    ->tooltip(fn (Product $record): ?string => $record->name),

                TextColumn::make('auto_create_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'warning',
                        'pending_review', 'approved' => 'info',
                        'rejected', 'needs_brand_or_category_assignment', 'variations_not_supported_v1' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('brand_id')
                    ->label('Brand')
                    ->state(fn (Product $record): string => $record->brand_id === null
                        ? '— missing —'
                        : "#{$record->brand_id}")
                    ->badge()
                    ->color(fn (string $state): string => $state === '— missing —' ? 'danger' : 'gray'),

                TextColumn::make('category_id')
                    ->label('Category')
                    ->state(fn (Product $record): string => $record->category_id === null
                        ? '— missing —'
                        : "#{$record->category_id}")
                    ->badge()
                    ->color(fn (string $state): string => $state === '— missing —' ? 'danger' : 'gray'),

                TextColumn::make('images_count')
                    ->label('Images')
                    ->state(fn (Product $record): int => count((array) $record->gallery_image_urls))
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'success'),

                TextColumn::make('woo_product_id')
                    ->label('Woo ID')
                    ->state(fn (Product $record): string => $record->woo_product_id === null
                        ? '— not pushed —'
                        : (string) $record->woo_product_id)
                    ->badge()
                    ->color(fn (string $state): string => $state === '— not pushed —' ? 'danger' : 'gray'),

                TextColumn::make('issue_summary')
                    ->label('Issues')
                    ->state(static function (Product $record): string {
                        $issues = [];
                        if (empty((array) $record->gallery_image_urls)) {
                            $issues[] = 'no images';
                        }
                        if ($record->brand_id === null) {
                            $issues[] = 'no brand';
                        }
                        if ($record->category_id === null) {
                            $issues[] = 'no category';
                        }
                        if ($record->woo_product_id === null) {
                            $issues[] = 'no Woo';
                        }

                        return implode(', ', $issues);
                    })
                    ->wrap(),

                TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->actions([
                Action::make('resync')
                    ->label('Resync to Woo')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Product $record): string => "Resync {$record->sku} to Woo")
                    ->modalDescription('Runs products:resync-to-woo --skus=<sku>. Re-pushes tags + regular_price + attributes to the existing Woo product. Takes 5–15s. Safe to repeat.')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->action(function (Product $record): void {
                        try {
                            Log::info('AutoCreateHealthPage: resync invoked', [
                                'sku' => $record->sku,
                                'actor_id' => auth()->id(),
                            ]);
                            // Array option form — never concatenate user input
                            // into a shell-style string (T-mx9 boundary 2).
                            Artisan::call('products:resync-to-woo', ['--skus' => $record->sku]);

                            Notification::make()
                                ->success()
                                ->title("Resync dispatched for {$record->sku}")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Resync failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Action::make('source_images')
                    ->label('Source images')
                    ->icon('heroicon-o-photo')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Product $record): string => "Source images for {$record->sku}")
                    ->modalDescription('Runs products:source-images --skus=<sku>. Calls Icecat + supplier feed + web search + Claude vision (~10p). Use only when local gallery is empty.')
                    ->visible(fn (Product $record): bool => empty((array) $record->gallery_image_urls)
                        && (bool) auth()->user()?->hasRole('admin'))
                    ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
                    ->action(function (Product $record): void {
                        try {
                            Log::info('AutoCreateHealthPage: source-images invoked', [
                                'sku' => $record->sku,
                                'actor_id' => auth()->id(),
                            ]);
                            Artisan::call('products:source-images', ['--skus' => $record->sku]);

                            Notification::make()
                                ->success()
                                ->title("Source-images dispatched for {$record->sku}")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Source-images failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * Single source of truth for the unhealthy predicate. Both the
     * table query AND the nav badge count call this so they cannot
     * drift — same drift-prevention pattern as 260606-lhp's
     * Suggestion::scopeHighConfidenceSourceable extraction.
     */
    private static function unhealthyQuery(): Builder
    {
        $emptyImagesExpr = static::emptyImagesExpr();

        return Product::query()
            ->where('auto_create_status', '!=', 'manual')
            ->where(function (Builder $q) use ($emptyImagesExpr): void {
                $q->whereNull('gallery_image_urls')
                    ->orWhereRaw($emptyImagesExpr)
                    ->orWhereNull('brand_id')
                    ->orWhereNull('category_id')
                    ->orWhereNull('woo_product_id');
            });
    }

    /**
     * Driver-aware JSON length expression. Compile-time string
     * literal — driver name is from getDriverName(), never user
     * input, so no SQLi (T-mx9-03 disposition: accept).
     */
    private static function emptyImagesExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'json_array_length(gallery_image_urls) = 0'
            : 'JSON_LENGTH(gallery_image_urls) = 0';
    }
}
