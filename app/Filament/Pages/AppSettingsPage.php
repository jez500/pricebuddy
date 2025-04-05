<?php

namespace App\Filament\Pages;

use App\Enums\Icons;
use App\Enums\IntegratedServices;
use App\Enums\NotificationMethods;
use App\Filament\Traits\FormHelperTrait;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\LocaleHelper;
use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Filament\Forms\Components\Actions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use App\Notifications\Messages\GotifyMessage;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;

class AppSettingsPage extends SettingsPage
{
    use FormHelperTrait;

    const NOTIFICATION_SERVICES_KEY = 'notification_services';

    const INTEGRATED_SERVICES_KEY = 'integrated_services';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Settings';

    protected static ?string $navigationGroup = 'System';

    protected static string $settings = AppSettings::class;

    protected static ?int $navigationSort = 100;

    public function save(): void
    {
        parent::save();

        Cache::flush();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Scrape Settings')
                    ->description(__('Settings for scraping'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TimePicker::make('scrape_schedule_time')
                            ->label('Fetch schedule time')
                            ->seconds(false)
                            ->hintIcon(Icons::Help->value, 'The time of day to get product prices')
                            ->required(),
                        TextInput::make('scrape_cache_ttl')
                            ->label('Scrape cache ttl')
                            ->hintIcon(Icons::Help->value, 'After a page is scraped, how many minutes will be the page html be cached for')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('sleep_seconds_between_scrape')
                            ->label('Seconds to wait before fetching next page')
                            ->hintIcon(Icons::Help->value, 'It is recommended to wait a few seconds between fetching pages to prevent being blocked')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('max_attempts_to_scrape')
                            ->label('Max scrape attempts')
                            ->hintIcon(Icons::Help->value, 'How many times to attempt to scrape a page before giving up')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),

                Section::make('Locale')
                    ->description(__('Default region and locale settings'))
                    ->columns(2)
                    ->schema(self::getLocaleFormFields('default_locale_settings')),

                Section::make('Logging')
                    ->description(__('Settings for logging'))
                    ->columns(2)
                    ->schema([
                        Select::make('log_retention_days')
                            ->label('Log retention days')
                            ->options([
                                7 => '7 days',
                                14 => '14 days',
                                30 => '30 days',
                                90 => '90 days',
                                180 => '180 days',
                                365 => '365 days',
                            ])
                            ->hintIcon(Icons::Help->value, 'How many days to keep logs for')
                            ->required(),
                    ]),

                self::makeFormHeading('Notifications'),

                $this->getEmailSettings(),
                $this->getPushoverSettings(),
                $this->getGotifySettings(),

                self::makeFormHeading('Integrations'),

                $this->getSearXngSettings(),
            ]);
    }

    protected function getEmailSettings(): Section
    {
        return self::makeSettingsSection(
            'Email',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Mail->value,
            [
                TextInput::make('smtp_host')
                    ->label('SMTP host')
                    ->hintIcon(Icons::Help->value, 'Host domain or IP address of the SMTP server')
                    ->required(),
                TextInput::make('smtp_port')
                    ->label('SMTP Port')
                    ->hintIcon(Icons::Help->value, 'The port of the SMTP server')
                    ->required()
                    ->default('25'),
                TextInput::make('smtp_user')
                    ->label('SMTP Username')
                    ->hintIcon(Icons::Help->value, 'The optional username for the SMTP server'),
                TextInput::make('smtp_password')
                    ->password()
                    ->label('SMTP Password')
                    ->hintIcon(Icons::Help->value, 'The optional password for the SMTP server'),
                TextInput::make('from_address')
                    ->required()
                    ->label('From address')
                    ->hintIcon(Icons::Help->value, 'The email address to send emails from'),
                Select::make('encryption')
                    ->label('Encryption')
                    ->placeholder('None')
                    ->options([
                        'tls' => 'TLS',
                        'ssl' => 'SSL',
                    ])
                    ->hintIcon(Icons::Help->value, 'The encryption method to use when sending emails'),
            ],
            __('SMTP settings for sending emails')
        );
    }

    protected function getPushoverSettings(): Section
    {
        return self::makeSettingsSection(
            'Pushover',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Pushover->value,
            [
                TextInput::make('token')
                    ->label('Pushover token')
                    ->hint(new HtmlString('<a href="https://pushover.net/apps/build" target="_blank">Create an application</a>'))
                    ->required(),
            ],
            __('Push notifications via Pushover')
        );
    }

    protected function getGotifySettings(): Section
    {
        return self::makeSettingsSection(
            'Gotify',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Gotify->value,
            [
                TextInput::make('url')
                    ->label('Gotify server URL')
                    ->placeholder('https://gotify.example.com')
                    ->required(),
                TextInput::make('token')
                    ->label('Application token')
                    ->required()
                    ->password()
                    ->suffixAction(
                        Action::make('testGotify')
                            ->label('Test')
                            ->icon('heroicon-m-bell')
                            ->action(fn () => $this->testGotifyNotification())
                    ),
            ],
            __('Push notifications via Gotify')
        );
    }

    protected function getSearXngSettings(): Section
    {
        return self::makeSettingsSection(
            'SearXng',
            self::INTEGRATED_SERVICES_KEY,
            IntegratedServices::SearXng->value,
            [
                TextInput::make('url')
                    ->label('SearXng url')
                    ->placeholder('https://searxng.homelab.com/search')
                    ->hintIcon(Icons::Help->value, __('Url of your SearXng instance, including the search path'))
                    ->required(),
                TextInput::make('search_prefix')
                    ->label('Search prefix')
                    ->placeholder('Buy')
                    ->hintIcon(Icons::Help->value, __('Text to prepend to the product name when searching'))
                    ->required(),
            ],
            new HtmlString('Automatically search for additional products urls via <a href="https://searxng.org/" target="_blank">SearXng</a>')
        );
    }

    protected function testGotifyNotification(): void
    {
        $settings = $this->form->getState()['notification_services']['gotify'] ?? null;
        
        if (!$settings || empty($settings['url']) || empty($settings['token'])) {
            Notification::make()
                ->title('Error')
                ->body('Please save your Gotify settings first')
                ->danger()
                ->send();
            return;
        }

        try {
            $url = rtrim($settings['url'], '/').'/message?token='.$settings['token'];
            
            $response = Http::post($url, [
                'title' => 'Test Notification',
                'message' => 'This is a test notification from PriceBuddy',
                'priority' => 5,
                'extras' => [
                    'client::notification' => [
                        'click' => url('/'),
                    ],
                ],
            ]);
            
            $response->throw();
            
            Notification::make()
                ->title('Success')
                ->body('Test notification sent successfully')
                ->success()
                ->send();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Notification::make()
                ->title('Failed to send test notification')
                ->body('HTTP Error: ' . $e->getMessage())
                ->danger()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to send test notification')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getLocaleFormFields(string $settingsKey): array
    {
        return [
            Select::make($settingsKey.'.locale')
                ->label('Locale')
                ->searchable()
                ->options(LocaleHelper::getAllLocalesAsOptions())
                ->hintIcon(Icons::Help->value, 'Primarily used when extracting and displaying prices. Help translate this app on GitHub')
                ->required()
                ->default(CurrencyHelper::getLocale()),
            Select::make($settingsKey.'.currency')
                ->label('Currency')
                ->searchable()
                ->options(LocaleHelper::getAllCurrencyLocalesAsOptions())
                ->hintIcon(Icons::Help->value, 'Default currency for extracting and displaying prices')
                ->required()
                ->default(CurrencyHelper::getCurrency()),
        ];
    }
}
