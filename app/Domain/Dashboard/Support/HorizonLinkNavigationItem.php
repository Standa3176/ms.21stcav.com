<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Support;

use Filament\Navigation\NavigationItem;

/**
 * Phase 7 Plan 02 — D-03 Horizon link in the admin sidebar.
 *
 * Provides a single factory `build()` that returns a Filament NavigationItem
 * pointing at `/horizon` (opens in a new tab). Visibility is gated by the
 * `admin` role check — pricing_manager / sales / read_only never see the
 * link even if Filament exposes the nav group.
 *
 * Registered via `AdminPanelProvider::panel()->navigationItems([...])`.
 * Kept out of the generic Filament/Pages directory because the class is
 * a pure helper (not a Page), and grouping it under Dashboard/Support mirrors
 * the convention Phase 4 used for CRM helpers.
 *
 * Admin-only gate pattern mirrors HorizonServiceProvider::gate() (Phase 1
 * Plan 05 FOUND-09): the /horizon route itself is also admin-gated, so this
 * link is belt-and-braces — hiding the UI affordance AND blocking the route.
 */
final class HorizonLinkNavigationItem
{
    public static function build(): NavigationItem
    {
        return NavigationItem::make('Horizon')
            ->icon('heroicon-o-queue-list')
            ->url('/horizon', shouldOpenInNewTab: true)
            ->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
            ->group('Operations')
            ->sort(90);
    }
}
