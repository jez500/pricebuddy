<?php

namespace App\Providers\Filament;

use App\Filament\Pages\HomeDashboard;
use App\Filament\Pages\Login;
use App\Filament\Pages\StatusPage;
use App\Filament\Plugins\ApiKeyPlugin;
use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\LogMessageResource;
use App\Filament\Resources\NotificationHistoryResource;
use Awcodes\FilamentQuickCreate\QuickCreatePlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentSpotlight\SpotlightPlugin;

class AdminPanelProvider extends PanelProvider
{
    public const array PRIMARY_COLOR = Color::Teal;

    public const array DEFAULT_PAGINATION = [25, 50, 100, 'all'];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandLogo(fn () => view('filament.logo'))
            ->favicon(asset('/favicon.ico'))
            ->login(Login::class)
            ->maxContentWidth(MaxWidth::Full)
            ->colors([
                'primary' => self::PRIMARY_COLOR,
            ])
            ->navigationItems([
                NavigationItem::make('Help')
                    ->group('System')
                    ->sort(1000)
                    ->url(config('price_buddy.help_url'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-question-mark-circle'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                HomeDashboard::class,
                StatusPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // These get auto discovered, if not add manually.
            ])
            ->plugins([
                ApiKeyPlugin::make(),
                SpotlightPlugin::make(),
                QuickCreatePlugin::make()
                    ->excludes([
                        LogMessageResource::class,
                    ]),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Notifications')
                    ->icon('heroicon-o-bell')
                    ->url(fn (): string => NotificationHistoryResource::getUrl('index')),
                MenuItem::make()
                    ->label('API keys')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ApiKeyResource::getUrl('index')),
            ])
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
            ])
            ->breadcrumbs(false)
            ->databaseNotifications();
    }
}
