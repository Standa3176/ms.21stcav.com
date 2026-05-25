<?php

declare(strict_types=1);

namespace App\Filament\Pages\Admin;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

/**
 * Admin-only console for the three legacy Stock Updater bulk actions:
 *   1. Reset all margin overrides to 0
 *   2. Bulk-publish pending products that now have a buy_price
 *   3. Run all retention prunes on demand
 *
 * Each action prompts a confirm modal before executing. Admin-only via
 * canAccess() — page hidden from non-admins entirely.
 */
final class StockUpdaterAdminPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Stock Updater Actions';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Stock Updater Actions';

    protected static string $view = 'filament.pages.admin.stock-updater-admin';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset_overrides')
                ->label('Reset all margin overrides')
                ->color('warning')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->modalHeading('Reset every ProductOverride margin to 0?')
                ->modalDescription('Sets margin_basis_points = 0 on every ProductOverride row. Default tier rules and brand/category rules are untouched. Cannot be undone.')
                ->action(function (): void {
                    $count = ProductOverride::query()->update(['margin_basis_points' => 0]);
                    Notification::make()
                        ->title("Reset {$count} ProductOverride row(s) to 0% margin")
                        ->success()
                        ->send();
                }),

            Action::make('publish_pending')
                ->label('Publish pending products')
                ->color('success')
                ->icon('heroicon-o-eye')
                ->requiresConfirmation()
                ->modalHeading('Publish all pending products with a buy_price?')
                ->modalDescription('Flips status from pending → publish for every Product where buy_price > 0. Products with NULL or zero buy_price stay pending.')
                ->action(function (): void {
                    $count = Product::query()
                        ->where('status', 'pending')
                        ->whereNotNull('buy_price')
                        ->where('buy_price', '>', 0)
                        ->update(['status' => 'publish']);
                    Notification::make()
                        ->title("Flipped {$count} product(s) from pending → publish")
                        ->success()
                        ->send();
                }),

            Action::make('run_prunes')
                ->label('Run retention prunes')
                ->color('gray')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Run every retention prune now?')
                ->modalDescription('Calls activitylog:prune, integration-events:prune, sync-errors:prune, sync-diffs:prune, competitor:csv-prune. Same commands the 03:00 cron cascade fires nightly — running on demand here is safe but redundant.')
                ->action(function (): void {
                    $commands = [
                        'activitylog:prune' => ['--days' => 365],
                        'integration-events:prune' => ['--days' => 90],
                        'sync-errors:prune' => ['--days' => 90],
                        'sync-diffs:prune' => [],
                        'competitor:csv-prune' => [],
                    ];
                    $results = [];
                    foreach ($commands as $signature => $params) {
                        try {
                            Artisan::call($signature, $params);
                            $results[] = "{$signature} OK";
                        } catch (\Throwable $e) {
                            $results[] = "{$signature} FAILED: ".$e->getMessage();
                        }
                    }
                    Notification::make()
                        ->title('Prune sweep complete')
                        ->body(implode("\n", $results))
                        ->success()
                        ->send();
                }),
        ];
    }
}
