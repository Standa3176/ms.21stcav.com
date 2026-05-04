<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // violet-800 — deliberate B2B-operational tone (vs vibrant violet-600 SaaS-trial vibe).
            // Color::hex() generates the 50..950 shade ramp from the seed colour; Filament uses
            // the lighter shades for hover/active surfaces and the darker ones for ring/focus.
            ->colors([
                'primary' => Color::hex('#5B21B6'),
            ])
            // Quick task 260504-ev5 — explicit 8-group nav order. Filament defaults
            // to alphabetical group ordering; this enforces the domain-aligned
            // sequence matching ops mental model (left → top of the sidebar):
            //   Operations  — daily ambient (Home, Horizon, Notifications)
            //   Catalogue   — product/pricing master data
            //   Competitors — competitor intelligence
            //   FTP & CSV   — feed plumbing (creds + feeds + ingest forensics)
            //   WooCommerce — Woo-bound jobs + import triage
            //   CRM & Bitrix — CRM mapping + push log + pipeline config
            //   Review      — human-in-the-loop triage queues
            //   Admin       — credentials + alert recipients (rare-touch)
            ->navigationGroups([
                'Operations',
                'Catalogue',
                'Competitors',
                'FTP & CSV',
                'WooCommerce',
                'CRM & Bitrix',
                'Review',
                'Admin',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
            //
            // History: HorizonLinkNavigationItem (new-tab link) → HorizonEmbedPage
            // (single iframe) → HorizonCluster (Cluster + 8 iframe sub-pages — gave
            // double-sidebar UX) → THIS: 8 native Filament Pages reading directly
            // from Laravel\Horizon's PHP repository contracts. No iframe at all.
            //
            // ->discoverClusters() removed entirely (no other clusters exist; Filament
            // tolerates its absence). The 8 native pages live under
            // app/Filament/Pages/Horizon/ and are picked up by the existing
            // ->discoverPages(in: app_path('Filament/Pages'), ...) line above —
            // Filament's auto-discovery walks subdirectories. Sidebar collapsing
            // is achieved via static $navigationParentItem on the 7 child pages
            // (Filament 3.3 native parent-child nav).
            //
            // HorizonLinkNavigationItem.php is intentionally retained on disk
            // (unused) as the rollback path — re-register it via navigationItems()
            // and the new pages disappear behind a single admin-gated link.
            ->pages([
                // Phase 7 Plan 02 — HomeDashboardPage overrides the default Filament
                // dashboard at /admin (D-01, 9-widget grid). Registered first so it
                // wins the root slug; Pages\Dashboard retained as a safety fallback
                // but Filament 3 honours the first Dashboard-subclass registration.
                \App\Filament\Pages\HomeDashboardPage::class,
                // Phase 7 Plan 04 — unified notification centre at /admin/notifications.
                // 4 tabs (failed-jobs / stale-feeds / pending-suggestions / webhook-dlq)
                // aggregated by NotificationCentreAggregator; Livewire wire:poll refreshes.
                \App\Filament\Pages\NotificationCentrePage::class,
                // Phase 7 Plan 02 — D-03 native Horizon Pages (8) — explicit registration
                // belt-and-braces alongside ->discoverPages(...) above. Discovery would
                // pick them up anyway; explicit list gives static analysers + grep an
                // anchor + makes the parent-child nav order legible at a glance.
                \App\Filament\Pages\Horizon\HorizonDashboardPage::class,
                \App\Filament\Pages\Horizon\HorizonMonitoringPage::class,
                \App\Filament\Pages\Horizon\HorizonMetricsPage::class,
                \App\Filament\Pages\Horizon\HorizonBatchesPage::class,
                \App\Filament\Pages\Horizon\HorizonPendingJobsPage::class,
                \App\Filament\Pages\Horizon\HorizonCompletedJobsPage::class,
                \App\Filament\Pages\Horizon\HorizonSilencedJobsPage::class,
                \App\Filament\Pages\Horizon\HorizonFailedJobsPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Phase 7 Plan 02 — 9 home-dashboard widgets (D-01).
                // Order here doesn't drive render; HomeDashboardPage::getWidgets()
                // is authoritative for layout. Listing here ensures Filament
                // resolves classes + applies policy gates (canView) correctly.
                \App\Filament\Widgets\LastSyncRunWidget::class,
                \App\Filament\Widgets\CrmPushSuccessRateWidget::class,
                \App\Filament\Widgets\CompetitorFreshnessWidget::class,
                \App\Filament\Widgets\PendingReviewsWidget::class,
                \App\Filament\Widgets\ImportIssuesWidget::class,
                \App\Filament\Widgets\HorizonFailedJobsWidget::class,
                \App\Filament\Widgets\SyncDiffsParityWidget::class,
                \App\Filament\Widgets\ProductCatalogueHealthWidget::class,
                \App\Filament\Widgets\WeeklyReportStatusWidget::class,
            ])
            // Phase 7 Plan 02 — D-03 Horizon link was previously registered here
            // via HorizonLinkNavigationItem::build() (opens /horizon in new tab).
            // Replaced post-09.1 by HorizonEmbedPage (registered in ->pages([...])
            // above) which embeds /horizon in an iframe inside Filament chrome.
            // HorizonLinkNavigationItem.php is intentionally retained on disk
            // (unused) as the documented rollback path — see HorizonEmbedPage
            // class docblock.
            // Per-domain Resource discovery (modules populate in later plans — 01-RESEARCH.md §1):
            ->discoverResources(in: app_path('Domain/Suggestions/Filament/Resources'), for: 'App\\Domain\\Suggestions\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Alerting/Filament/Resources'), for: 'App\\Domain\\Alerting\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Sync/Filament/Resources'), for: 'App\\Domain\\Sync\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Products/Filament/Resources'), for: 'App\\Domain\\Products\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Pricing/Filament/Resources'), for: 'App\\Domain\\Pricing\\Filament\\Resources')
            // Phase 4 Plan 04 — CRM Resources + Pages (CrmPipelineSettingsPage is a singleton Page).
            ->discoverResources(in: app_path('Domain/CRM/Filament/Resources'), for: 'App\\Domain\\CRM\\Filament\\Resources')
            ->discoverPages(in: app_path('Domain/CRM/Filament/Pages'), for: 'App\\Domain\\CRM\\Filament\\Pages')
            // Phase 5 Plan 04a — Competitor Intelligence Resources (price / ingest-run / csv-parse-error).
            ->discoverResources(in: app_path('Domain/Competitor/Filament/Resources'), for: 'App\\Domain\\Competitor\\Filament\\Resources')
            // Phase 5 Plan 04b — Competitor Intelligence Pages (analysis + csv-ingest-issues) + Widgets (trend chart, biggest deltas, stale-feed traffic light).
            ->discoverPages(in: app_path('Domain/Competitor/Filament/Pages'), for: 'App\\Domain\\Competitor\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Domain/Competitor/Filament/Widgets'), for: 'App\\Domain\\Competitor\\Filament\\Widgets')
            // Phase 6 Plan 04 — ProductAutoCreate Resources (Review inbox + Skip Rules) + AutoCreateSettingsPage singleton.
            ->discoverResources(in: app_path('Domain/ProductAutoCreate/Filament/Resources'), for: 'App\\Domain\\ProductAutoCreate\\Filament\\Resources')
            ->discoverPages(in: app_path('Domain/ProductAutoCreate/Filament/Pages'), for: 'App\\Domain\\ProductAutoCreate\\Filament\\Pages')
            // Phase 8 Plan 04 — C4 Agent Framework AgentRunResource (admin-only,
            // read-only) under /admin/agent-runs. Lists AgentRun forensics rows
            // with kind/status/cost/date filters; detail view renders 7 sections
            // including the "Guardrail Failures" JSON viewer (BLOCKER 1) +
            // Langfuse trace deep-link + linked Suggestions summary.
            ->discoverResources(in: app_path('Domain/Agents/Filament/Resources'), for: 'App\\Domain\\Agents\\Filament\\Resources')
            // Phase 9 Plan 05 — TradePricing customer_groups CRUD under "Pricing" nav group.
            ->discoverResources(in: app_path('Domain/TradePricing/Filament/Resources'), for: 'App\\Domain\\TradePricing\\Filament\\Resources')
            // Phase 11 Plan 03 — Quotes domain Filament Resource (QuoteResource +
            // QuoteLinesRelationManager + 4 state-machine Actions). Adds new
            // "Sales" navigation group (first member; future v1.x can add
            // CustomerResource + InvoiceResource here per CONTEXT.md Claude's
            // Discretion). Per-domain Resource discovery follows the same
            // pattern as Phase 4 CRM + Phase 5 Competitor + Phase 8 Agents.
            ->discoverResources(in: app_path('Domain/Quotes/Filament/Resources'), for: 'App\\Domain\\Quotes\\Filament\\Resources')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
