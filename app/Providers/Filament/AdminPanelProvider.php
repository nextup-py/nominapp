<?php

namespace App\Providers\Filament;

use App\Settings\GeneralSettings;
use App\Support\ThemeResolver;
use EightyNine\FilamentDocs\FilamentDocsPlugin;
use Filament\FontProviders\LocalFontProvider;
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
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        try {
            $settings = app(GeneralSettings::class);
            $colorKey = $settings->primary_color;
            $fontKey = $settings->font;
        } catch (\Throwable) {
            $colorKey = ThemeResolver::DEFAULT_COLOR;
            $fontKey = ThemeResolver::DEFAULT_FONT;
        }

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->profile(isSimple: false)
            ->favicon(asset('icons/favicon.ico'))
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => ThemeResolver::colorPalette($colorKey),
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

        // No basta con file_exists(manifest.json): un manifest presente pero
        // desactualizado (deploy en curso, composer install corre antes que
        // npm run build) puede no tener la entrada de ESTA fuente puntual —
        // ya ocurrió en producción (deploy a nextup-demo tras sub-proyecto F,
        // manifest viejo sin el split de fonts/{key}.css). Vite::asset() debe
        // ir dentro del try/catch, no solo detrás del file_exists().
        try {
            $panel->font(
                ThemeResolver::fontFamily($fontKey),
                url: Vite::asset(ThemeResolver::fontAssetPath($fontKey)),
                provider: LocalFontProvider::class,
            );
        } catch (\Throwable) {
            // Sin fuente self-hosted disponible, Filament cae a su provider
            // default (BunnyFontProvider) — degradación aceptable, nunca debe
            // tumbar el boot de la app.
        }

        return $panel;
    }
}
