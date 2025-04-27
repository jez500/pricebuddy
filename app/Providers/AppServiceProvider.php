<?php

namespace App\Providers;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Policies\ProductPolicy;
use App\Services\Helpers\NotificationsHelper;
use App\Services\Helpers\QueueHelper;
use App\Services\Helpers\SettingsHelper;
use Filament\Facades\Filament;
use Filament\Navigation\MenuItem;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerFilamentSettings();
        $this->setConfigFromAppSettings();
        $this->registerAbout();
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Product::class, ProductPolicy::class);
    }

    protected function registerFilamentSettings(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Blade::render(
                '@vite([\'resources/scss/app.scss\', \'resources/js/app.js\'])'.
                '@include(\'body.js-settings\')'.
                '<link rel="manifest" href="/manifest.json">'.
                '<script src="/js/clipboard.js"></script>'
            ),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            fn (): string => view('components.sidebar-footer', [
                'content' => config('app.version', 'development'),
            ]),
        );

        Filament::registerUserMenuItems([
            MenuItem::make()
                ->label(__('Account settings'))
                ->url(fn () => '/admin/users/'.auth()->id().'/edit')
                ->icon('heroicon-s-cog'),
        ]);
    }

    protected function setConfigFromAppSettings(): void
    {
        // Email.
        $mail = NotificationMethods::Mail->value;
        if (NotificationsHelper::isEnabled($mail)) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => NotificationsHelper::getSetting($mail, 'smtp_host'),
                'mail.mailers.smtp.port' => NotificationsHelper::getSetting($mail, 'smtp_port'),
                'mail.mailers.smtp.username' => NotificationsHelper::getSetting($mail, 'smtp_user'),
                'mail.mailers.smtp.password' => NotificationsHelper::getSetting($mail, 'smtp_password'),
                'mail.mailers.smtp.encryption' => NotificationsHelper::getSetting($mail, 'encryption') ?: null,
                'mail.from.address' => NotificationsHelper::getSetting($mail, 'from_address', 'hello@example.com'),
            ]);
        }

        // Pushover.
        $pushover = NotificationMethods::Pushover->value;
        if (NotificationsHelper::isEnabled($pushover)) {
            config([
                'services.pushover.token' => NotificationsHelper::getSetting($pushover, 'token'),
            ]);
        }

        // Logging.
        config([
            'logging.channels.db.days' => SettingsHelper::getSetting('log_retention_days', 7),
        ]);
    }

    protected function registerAbout(): void
    {
        AboutCommand::add('PriceBuddy', fn () => [
            'Version' => config('app.version', 'development'),
            'Queue Worker' => QueueHelper::isRunning() ? 'Running' : 'Stopped',
        ]);
    }
}
