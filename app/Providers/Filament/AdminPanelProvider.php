<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
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
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            // Per-domain Resource discovery (modules populate in later plans — 01-RESEARCH.md §1):
            ->discoverResources(in: app_path('Domain/Suggestions/Filament/Resources'), for: 'App\\Domain\\Suggestions\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Alerting/Filament/Resources'), for: 'App\\Domain\\Alerting\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Sync/Filament/Resources'), for: 'App\\Domain\\Sync\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Products/Filament/Resources'), for: 'App\\Domain\\Products\\Filament\\Resources')
            ->discoverResources(in: app_path('Domain/Pricing/Filament/Resources'), for: 'App\\Domain\\Pricing\\Filament\\Resources')
            // Phase 4 Plan 04 — CRM Resources + Pages (CrmPipelineSettingsPage is a singleton Page).
            ->discoverResources(in: app_path('Domain/CRM/Filament/Resources'), for: 'App\\Domain\\CRM\\Filament\\Resources')
            ->discoverPages(in: app_path('Domain/CRM/Filament/Pages'), for: 'App\\Domain\\CRM\\Filament\\Pages')
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
