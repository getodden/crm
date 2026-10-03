<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Odden\Filament\OddenPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Odden CRM')
            ->brandLogo(asset('brand/odden-logo-light.svg'))
            ->darkModeBrandLogo(asset('brand/odden-logo-dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand/favicon.svg'))
            // Explicit brand palette: Filament's Color::Violet is Tailwind v4's (600 = #7f22fe), not #7c3aed.
            ->colors([
                'primary' => [
                    50 => '#f5f3ff',
                    100 => '#ede9fe',
                    200 => '#ddd6fe',
                    300 => '#c4b5fd',
                    400 => '#a78bfa',
                    500 => '#8b5cf6',
                    600 => '#7c3aed',
                    700 => '#6d28d9',
                    800 => '#5b21b6',
                    900 => '#4c1d95',
                    950 => '#2e1065',
                ],
            ])
            ->plugins([
                OddenPlugin::make(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
