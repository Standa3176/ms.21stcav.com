<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ListSuggestions extends ListRecords
{
    protected static string $resource = SuggestionResource::class;

    /**
     * Tabs split pending new-product-opportunity suggestions by competitor
     * count (denormalised in evidence.supporting_competitors). Each tab
     * shows the count badge so the operator can see at-a-glance where
     * the strongest opportunities sit.
     *
     * The "All" tab is the default Filament view — kept first so the
     * existing operator flow (review the whole inbox) is unchanged. The
     * three pivot tabs (3+ / 2 / 1) appear after.
     *
     * Each pivot tab also gets a "Auto-create all sourceable in this tab"
     * header action (see getHeaderActions) — fires the
     * products:draft-from-suggestions pipeline against the visible SKU
     * list. Sourceability + brand-on-Woo filter still applies at the
     * command level so non-sourceable SKUs are skipped, never written.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->icon('heroicon-o-rectangle-stack'),

            '3-plus' => Tab::make('3+ competitors')
                ->icon('heroicon-o-fire')
                ->modifyQueryUsing(fn (Builder $q) => $this->scopeNewProductPending($q)
                    ->where('evidence->supporting_competitors', '>=', 3))
                ->badge($this->countForTab('3plus')),

            '2-competitors' => Tab::make('2 competitors')
                ->icon('heroicon-o-arrow-trending-up')
                ->modifyQueryUsing(fn (Builder $q) => $this->scopeNewProductPending($q)
                    ->where('evidence->supporting_competitors', 2))
                ->badge($this->countForTab('2')),

            '1-competitor' => Tab::make('1 competitor')
                ->icon('heroicon-o-flag')
                ->modifyQueryUsing(fn (Builder $q) => $this->scopeNewProductPending($q)
                    ->where('evidence->supporting_competitors', 1))
                ->badge($this->countForTab('1')),
        ];
    }

    /**
     * Page-level header action: pull the SKU list visible on the CURRENT
     * tab (capped at 100 to bound cost/time), build a confirm modal
     * with cost estimate, and on confirm queue the
     * products:draft-from-suggestions command with --auto-approve
     * --source-images --no-confirm + the explicit --skus list.
     *
     * The artisan command itself applies the sourceability + brand-on-Woo
     * filters so SKUs that aren't on supplier_db get silently dropped at
     * the per-SKU level — operator sees the final tally in Horizon /
     * laravel.log.
     *
     * Hidden when the active tab is "All" (auto-create on the entire
     * inbox isn't safe; operator must narrow first).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $tabKey = $this->activeTab;
        $isPivotTab = in_array($tabKey, ['3-plus', '2-competitors', '1-competitor'], true);

        if (! $isPivotTab) {
            return [];
        }

        return [
            Action::make('autoCreateInTab')
                ->label('Auto-create all in this tab')
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Auto-create products from this tab')
                ->modalDescription(function () use ($tabKey): string {
                    $skus = $this->skusForTab($tabKey, 100);
                    $count = count($skus);
                    $estimate = $count * 13; // ~13p per product (drafts + tax + images + publish)

                    return sprintf(
                        'This will queue the products:draft-from-suggestions pipeline for up to %d SKUs from the "%s" tab. The command filters to SKUs on supplier_db + brand-on-Woo, then drafts content, sources images, and PUBLISHES directly to the live Woo storefront via --auto-approve. Estimated Claude spend: ~%dp (~£%s). Estimated time: ~%d minutes.',
                        $count,
                        $tabKey,
                        $estimate,
                        number_format($estimate / 100, 2),
                        max(1, (int) ceil($count * 0.5)), // ~30s/product
                    );
                })
                ->action(function () use ($tabKey): void {
                    $skus = $this->skusForTab($tabKey, 100);
                    if ($skus === []) {
                        Notification::make()
                            ->warning()
                            ->title('No eligible SKUs in this tab')
                            ->send();

                        return;
                    }

                    // Queue the command on the default queue — Horizon
                    // picks it up. --no-confirm bypasses the interactive
                    // prompt; --auto-approve publishes live; --source-images
                    // sources images inline.
                    Artisan::queue('products:draft-from-suggestions', [
                        '--skus' => implode(',', $skus),
                        '--source-images' => true,
                        '--auto-approve' => true,
                        '--no-confirm' => true,
                        '--limit' => count($skus), // explicit list — no over-count
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Auto-create batch queued')
                        ->body(sprintf(
                            'Dispatched products:draft-from-suggestions for %d SKU(s) from "%s" tab. Watch Horizon or storage/logs/laravel.log for progress.',
                            count($skus),
                            $tabKey,
                        ))
                        ->send();
                }),
        ];
    }

    /**
     * Common scope for the three pivot tabs — pending new_product_opportunity
     * only. The "All" tab uses the default query (still excludes
     * agent_guardrail_blocked per SuggestionResource::getEloquentQuery).
     */
    private function scopeNewProductPending(Builder $q): Builder
    {
        return $q
            ->where('kind', 'new_product_opportunity')
            ->where('status', Suggestion::STATUS_PENDING);
    }

    /**
     * Cheap COUNT query for the tab badges. Caps at 9999+ to avoid blowing
     * up rendering for huge counts.
     */
    private function countForTab(string $bucket): string
    {
        $q = Suggestion::query()
            ->where('kind', 'new_product_opportunity')
            ->where('status', Suggestion::STATUS_PENDING);

        match ($bucket) {
            '3plus' => $q->where('evidence->supporting_competitors', '>=', 3),
            '2' => $q->where('evidence->supporting_competitors', 2),
            '1' => $q->where('evidence->supporting_competitors', 1),
            default => null,
        };

        $count = $q->count();

        return $count > 9999 ? '9999+' : (string) $count;
    }

    /**
     * Pull the SKU list visible on the given tab. Capped at $limit to
     * bound runtime cost of the auto-create batch (operator can click
     * the button again after the first batch finishes).
     *
     * @return array<int, string>
     */
    private function skusForTab(string $tabKey, int $limit): array
    {
        $q = Suggestion::query()
            ->where('kind', 'new_product_opportunity')
            ->where('status', Suggestion::STATUS_PENDING);

        match ($tabKey) {
            '3-plus' => $q->where('evidence->supporting_competitors', '>=', 3),
            '2-competitors' => $q->where('evidence->supporting_competitors', 2),
            '1-competitor' => $q->where('evidence->supporting_competitors', 1),
            default => null,
        };

        return $q
            ->orderBy('evidence->supporting_competitors', 'desc')
            ->limit($limit)
            ->pluck('evidence')
            ->map(static function ($evidence): string {
                $decoded = is_array($evidence)
                    ? $evidence
                    : (json_decode((string) $evidence, true) ?: []);

                return trim((string) ($decoded['sku'] ?? ''));
            })
            ->filter(static fn (string $s): bool => $s !== '')
            ->unique()
            ->values()
            ->all();
    }
}
