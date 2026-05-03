<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 UX-correction patch (post-09.1 follow-up).
 *
 * Replaces the original HorizonLinkNavigationItem ("open /horizon in new tab")
 * with a full Filament Page that wraps Horizon's dashboard inside an iframe.
 * The iframe sits inside Filament's standard `<x-filament-panels::page>` chrome
 * so the operator keeps the admin sidebar + global navigation visible while
 * monitoring the queue. No more context loss between admin work and queue
 * monitoring — operators asked for this during Phase 09.1 follow-up.
 *
 * Trade-off (documented in commit body):
 *   PRO — operators no longer lose admin chrome when watching jobs
 *   CON — small fragility risk if Horizon ships a CSP X-Frame-Options change
 *         in a future release (would break the iframe; rollback path below)
 *
 * Visibility gate mirrors HorizonLinkNavigationItem exactly — admin role only.
 * The /horizon route itself is also admin-gated by HorizonServiceProvider::gate
 * (Phase 1 Plan 05 FOUND-09); this canAccess() is belt-and-braces hiding the
 * UI affordance for non-admins.
 *
 * Rollback path:
 *   {@see \App\Domain\Dashboard\Support\HorizonLinkNavigationItem} is intentionally
 *   left in place (unused) for operator rollback if the iframe approach breaks.
 *   To revert: re-register HorizonLinkNavigationItem::build() on
 *   AdminPanelProvider::navigationItems([...]) and remove HorizonEmbedPage from
 *   the ->pages([...]) array.
 */
class HorizonEmbedPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'horizon-embed';

    protected static ?string $title = 'Horizon';

    protected static string $view = 'filament.pages.horizon-embed';

    /**
     * Page-level access gate — admin role only. Mirrors the exact visibility
     * check that HorizonLinkNavigationItem used (->visible(fn() => hasRole('admin')))
     * so the migration from NavigationItem to Page is RBAC-neutral.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
