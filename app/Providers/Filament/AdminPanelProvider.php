<?php

namespace App\Providers\Filament;

use EightyNine\FilamentDocs\FilamentDocsPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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
            ->path('')
            ->login()
            ->profile(isSimple: false)
            ->font('Poppins')
            ->favicon(asset('icons/favicon.ico'))
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Teal,
                'secondary' => Color::Amber,
                'success' => Color::Green,
                'danger' => Color::Red,
                'warning' => Color::Yellow,
                'info' => Color::Blue,
                'light' => Color::Gray,
                'dark' => Color::Slate,
            ])
            ->navigationGroups([
                NavigationGroup::make('Organización')
                    ->icon('heroicon-o-building-office'),
                NavigationGroup::make('Empleados')
                    ->icon('heroicon-o-user-group'),
                NavigationGroup::make('Asistencias')
                    ->icon('heroicon-o-clock'),
                NavigationGroup::make('Nóminas')
                    ->icon('heroicon-o-banknotes'),
                NavigationGroup::make('Créditos')
                    ->icon('heroicon-o-credit-card'),
                NavigationGroup::make('Reportes')
                    ->icon('heroicon-o-document-chart-bar'),
                NavigationGroup::make('Configuración')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
                NavigationGroup::make('Ayuda')
                    ->icon('heroicon-o-book-open')
                    ->collapsed(),
            ])
            ->databaseNotifications()
            ->plugin(FilamentDocsPlugin::make())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
