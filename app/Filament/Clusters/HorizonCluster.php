<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Phase 7 Plan 02 — D-03 UX-correction patch (post-09.1 follow-up — Cluster refactor).
 *
 * Groups 8 Horizon sub-pages under one collapsible "Horizon" sidebar entry inside
 * the "Operations" navigation group. Each child page (Dashboard, Monitoring,
 * Metrics, Batches, Pending/Completed/Silenced/Failed Jobs) is a thin
 * Filament Page that renders the same shared Blade view with a different
 * `horizonPath` via getViewData() — yielding 8 deep-link entries in the
 * sidebar instead of one undifferentiated "Horizon" iframe.
 *
 * Supersedes {@see \App\Filament\Pages\HorizonEmbedPage} (single-iframe page
 * shipped ~10 minutes prior). The supersession trade-off: gives operators
 * Filament-native sidebar deep-links straight to each Horizon section
 * (instead of nested iframe navigation inside a single embed page). Default
 * cluster click lands on Dashboard (lowest navigationSort = 10).
 *
 * Visibility gate mirrors the original HorizonLinkNavigationItem + HorizonEmbedPage
 * — admin role only. Each child Page also gates `canAccess()` admin-only as
 * defense-in-depth (Cluster::canAccessClusteredComponents iterates children
 * but a child whose canAccess() returns true would still be reachable
 * directly via URL if the Cluster gate were the only check). Pricing_manager,
 * sales, and read_only never see the Horizon group.
 *
 * Rollback path:
 *   {@see \App\Domain\Dashboard\Support\HorizonLinkNavigationItem} is intentionally
 *   left in place (unused) for operator rollback if the cluster approach breaks
 *   (e.g. Horizon ships a CSP X-Frame-Options change that kills the iframe).
 *   To revert: re-register HorizonLinkNavigationItem::build() on
 *   AdminPanelProvider::navigationItems([...]) and remove the
 *   ->discoverClusters(...) call.
 */
class HorizonCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 90;

    protected static ?string $clusterBreadcrumb = 'Horizon';

    protected static ?string $navigationLabel = 'Horizon';

    /**
     * Cluster-level access gate — admin role only. Defense in depth: each
     * child Page also enforces canAccess() admin-only so direct-URL hits
     * (e.g. /admin/horizon-cluster/failed-jobs) are gated even if a future
     * Filament version changes how Cluster::canAccessClusteredComponents
     * cascades.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
