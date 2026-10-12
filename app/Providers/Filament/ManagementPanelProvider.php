<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The panel for Manager (or anyone else holding an admin-area permission — see
 * Permissions::adminAreaPermissions() and User::canAccessPanel()), distinct from /admin, which is
 * Admin-only. Same Resources and Pages as /admin (discovered from the same directories, so there
 * is only one copy of each to maintain) — each is already gated by its own specific permission,
 * so what shows up here is exactly whatever that user actually holds, nothing more.
 *
 * Deliberately uses the same 'web' guard as the operator screens, so a Manager who signed in at
 * /operator/login is already authenticated here too — no separate login step. ->login() stays
 * registered only as a fallback for someone hitting this URL directly while signed out; the
 * normal path (a link from the operator screens, see components/layouts/operator.blade.php) never
 * exercises it.
 */
class ManagementPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('menaxhimi')
            ->path('menaxhimi')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The one way back to the operator screens from inside this panel — see
            // resources/views/filament/partials/back-to-operator.blade.php.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.partials.back-to-operator'),
            )
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
