<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SaleshubPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('saleshub')
            ->path('app')

            ->unsavedChangesAlerts()
            ->databaseNotifications()
            ->profile(isSimple: false)
            ->assets([
                \Filament\Support\Assets\Css::make('custom-stylesheet', asset('css/app/custom-stylesheet-saleshub.css')),
                \Filament\Support\Assets\Css::make('custom-stylesheet-fontawesome-all.min', asset('css/app/custom-stylesheet-fontawesome-all.min.css')),
            ])

            ->renderHook(
                'panels::head.end',
                fn() => new \Illuminate\Support\HtmlString(
                    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />' . "\n" .
                        '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>' . "\n" .
                        '<style>
                        .fi-topbar { padding-top: max(env(safe-area-inset-top, 28px), 28px) !important; z-index: 50 !important; }
                        body.dashboard-map-open .fi-main { position: relative; z-index: 0; }
                        </style>'

                        . "\n" .
                        '<script>
document.addEventListener("DOMContentLoaded", function () {
    document.addEventListener("focusin", function (e) {
        if (e.target.tagName !== "INPUT" && e.target.tagName !== "TEXTAREA") return;
        if (document.querySelector(".keyboard-scroll")) return;
        document.body.style.paddingBottom = "250px";
        document.documentElement.style.overflowY = "auto";
        document.body.style.overflowY = "auto";
        setTimeout(function () {
            e.target.scrollIntoView({ behavior: "smooth", block: "center" });
        }, 350);
    });
    document.addEventListener("focusout", function (e) {
        if (e.target.tagName !== "INPUT" && e.target.tagName !== "TEXTAREA") return;
        setTimeout(function () {
            var tag = document.activeElement ? document.activeElement.tagName : "";
            if (tag !== "INPUT" && tag !== "TEXTAREA" && tag !== "SELECT") {
                document.body.style.paddingBottom = "";
                document.documentElement.style.overflowY = "";
                document.body.style.overflowY = "";
            }
        }, 200);
    });
});
</script>'
                ),
            )




            ->login(\App\Filament\Pages\Auth\Login::class)

            ->brandLogo(asset("images/motolite-logo.png"))
            ->viteTheme('resources/css/filament/saleshub/theme.css')
            ->topNavigation()
            ->darkMode(false)



            ->colors([
                'primary' => Color::Red,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // AccountWidget::class,
                // FilamentInfoWidget::class,
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
            ->plugins([
                \Hammadzafar05\MobileBottomNav\MobileBottomNav::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
