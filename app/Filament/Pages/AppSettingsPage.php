<?php

namespace App\Filament\Pages;

use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Enums\Icons;
use App\Enums\IntegratedServices;
use App\Enums\NotificationMethods;
use App\Filament\Actions\Notifications\TestAppriseAction;
use App\Filament\Actions\Notifications\TestDiscordAction;
use App\Filament\Actions\Notifications\TestGotifyAction;
use App\Filament\Actions\Notifications\TestTelegramAction;
use App\Filament\Traits\FormHelperTrait;
use App\Models\UrlResearch;
use App\Rules\ValidCron;
use App\Services\AiService;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\IntegrationHelper;
use App\Services\Helpers\LocaleHelper;
use App\Services\Helpers\ScheduleHelper;
use App\Services\OllamaService;
use App\Services\SearchService;
use App\Settings\AppSettings;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

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

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Ollama model names fetched per provider, keyed by provider id.
     *
     * @var array<string, array<int, string>>
     */
    public array $ollamaModels = [];

    public function save(): void
    {
        parent::save();

        Cache::flush();
        Once::flush();
    }

    /**
     * Fetch installed Ollama models for a provider row and store them by id.
     */
    public function refreshOllamaModelsFor(string $providerId, ?string $baseUrl): void
    {
        if (blank($baseUrl)) {
            Notification::make()->title('Enter the Ollama base URL first.')->warning()->send();

            return;
        }

        try {
            $this->ollamaModels[$providerId] = OllamaService::new()->listModels($baseUrl);

            Notification::make()
                ->title('Loaded '.count($this->ollamaModels[$providerId]).' Ollama model(s).')
                ->success()
                ->send();
        } catch (\Throwable) {
            Notification::make()->title('Could not reach Ollama')->body("No response from {$baseUrl}.")->danger()->send();
        }
    }

    /**
     * Test the saved provider with the given id (requires the settings to be saved).
     */
    public function testProviderById(string $providerId): void
    {
        $provider = collect(IntegrationHelper::getAiProviders())
            ->first(fn ($p): bool => $p->id === $providerId);

        if ($provider === null) {
            Notification::make()->title('Save your settings before testing this provider.')->warning()->send();

            return;
        }

        $result = AiService::new()->testProviderConfig($provider);

        if ($result === true) {
            Notification::make()->title('Connection succeeded')->success()->send();

            return;
        }

        Notification::make()->title('Connection failed')->body($result)->danger()->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existingProviders = collect(
            data_get(AppSettings::new()->toArray(), 'integrated_services.ai.providers', [])
        );

        $providers = data_get($data, 'integrated_services.ai.providers', []);

        foreach ($providers as $index => $provider) {
            // Ollama has no API key.
            if (($provider['type'] ?? null) === AiProvider::Ollama->value) {
                continue;
            }

            $keyPath = "integrated_services.ai.providers.{$index}.api_key";
            $submitted = data_get($data, $keyPath);

            if (filled($submitted)) {
                try {
                    // Already-encrypted value — leave as-is (idempotent).
                    Crypt::decryptString($submitted);
                } catch (DecryptException) {
                    data_set($data, $keyPath, Crypt::encryptString($submitted));
                }

                continue;
            }

            // Blank submission: restore the stored ciphertext for this provider id.
            $storedKey = $existingProviders->firstWhere('id', $provider['id'] ?? null)['api_key'] ?? null;

            if (filled($storedKey)) {
                data_set($data, $keyPath, $storedKey);
            }
        }

        return $data;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Settings')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('General')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            $this->getLocaleSection(),
                            $this->getLoggingSection(),
                        ]),

                    Tabs\Tab::make('Scraping')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            $this->getScrapeSection(),
                            $this->getSearXngSettings(),
                        ]),

                    Tabs\Tab::make('Notifications')
                        ->icon('heroicon-o-bell')
                        ->schema([
                            $this->getEmailSettings(),
                            $this->getPushoverSettings(),
                            $this->getGotifySettings(),
                            $this->getAppriseSettings(),
                            $this->getTelegramSettings(),
                            $this->getDiscordSettings(),
                            $this->getNtfySettings(),
                        ]),

                    Tabs\Tab::make('AI')
                        ->icon('heroicon-o-sparkles')
                        ->schema([
                            $this->getAiSettings(),
                        ]),
                ]),
        ]);
    }

    protected function getScrapeSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Scrape Settings', __('Settings for scraping')),
            Group::make([
                TextInput::make('scrape_schedule')
                    ->label('Fetch schedule')
                    ->hintIcon(Icons::Help->value, 'Cron expression to control scraping. Use https://crontab.guru to build an expression.')
                    ->rule(new ValidCron)
                    ->live()
                    ->helperText(fn (Get $get) => ScheduleHelper::parseCronExpression($get('scrape_schedule')))
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
                TextInput::make('scrape_retry_max_attempts')
                    ->label('Max scrape attempts (incl. retries)')
                    ->hintIcon(Icons::Help->value, 'Total scrape attempts for a failed URL, including the original. Set to 1 to disable delayed retries.')
                    ->integer()
                    ->minValue(1)
                    ->required(),
                TextInput::make('scrape_retry_delay_minutes')
                    ->label('Minutes between retry attempts')
                    ->hintIcon(Icons::Help->value, 'How long to wait before retrying a failed URL scrape')
                    ->integer()
                    ->minValue(1)
                    ->required(),
            ])->columns(2),
        ]);
    }

    protected function getLocaleSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Locale', __('Default region and locale settings')),
            Group::make(self::getLocaleFormFields('default_locale_settings'))->columns(2),
        ]);
    }

    protected function getLoggingSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Logging', __('Settings for logging')),
            Group::make([
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
            ])->columns(2),
        ]);
    }

    protected function getEmailSettings(): Group
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
            __('SMTP settings for sending emails'),
            flat: true
        );
    }

    protected function getPushoverSettings(): Group
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
            __('Push notifications via Pushover'),
            flat: true
        );
    }

    protected function getGotifySettings(): Group
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
                        TestGotifyAction::make()
                            ->setSettings(fn () => $this->form->getState()['notification_services']['gotify'] ?? []),
                    ),
            ],
            __('Push notifications via Gotify'),
            flat: true
        );
    }

    protected function getAppriseSettings(): Group
    {
        return self::makeSettingsSection(
            'Apprise',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Apprise->value,
            [
                TextInput::make('url')
                    ->label('Apprise API server URL')
                    ->placeholder('https://apprise.example.com')
                    ->required(),
                TextInput::make('token')
                    ->label('Configuration token')
                    ->required()
                    ->suffixAction(
                        TestAppriseAction::make()
                            ->setSettings(fn () => data_get($this->form->getState(), 'notification_services.apprise', [])),
                    ),
            ],
            __('Push notifications via Apprise'),
            flat: true
        );
    }

    protected function getTelegramSettings(): Group
    {
        return self::makeSettingsSection(
            'Telegram',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Telegram->value,
            [
                TextInput::make('bot_token')
                    ->label('Bot token')
                    ->password()
                    ->required()
                    ->hint(new HtmlString('<a href="https://t.me/botfather" target="_blank">Create a bot</a>'))
                    ->hintIcon(Icons::Help->value, __('Create a bot with @BotFather and paste the token here. Each user then adds their own chat id in their profile.'))
                    ->suffixAction(
                        TestTelegramAction::make()
                            ->setSettings(fn () => data_get($this->form->getState(), 'notification_services.telegram', [])),
                    ),
            ],
            __('Push notifications via a Telegram bot'),
            flat: true
        );
    }

    protected function getDiscordSettings(): Group
    {
        return self::makeSettingsSection(
            'Discord',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Discord->value,
            [
                TextInput::make('webhook_url')
                    ->label('Default webhook URL')
                    ->url()
                    ->password()
                    ->revealable()
                    ->placeholder('https://discord.com/api/webhooks/...')
                    ->hintIcon(Icons::Help->value, __('Optional default Discord channel webhook. Users can override this with their own webhook in their profile.'))
                    ->suffixAction(
                        TestDiscordAction::make()
                            ->setSettings(fn () => data_get($this->form->getState(), 'notification_services.discord', [])),
                    ),
            ],
            __('Push notifications to a Discord channel via webhooks'),
            flat: true
        );
    }

    protected function getNtfySettings(): Group
    {
        return self::makeSettingsSection(
            'ntfy',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Ntfy->value,
            [
                TextInput::make('server_url')
                    ->label('Server URL')
                    ->url()
                    ->placeholder('https://ntfy.sh')
                    ->hintIcon(Icons::Help->value, __('Leave blank to use the public ntfy.sh server, or enter your self-hosted server URL.')),
                TextInput::make('username')
                    ->label('Username')
                    ->hintIcon(Icons::Help->value, __('Optional. Only needed for protected self-hosted servers.')),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->hintIcon(Icons::Help->value, __('Optional. Only needed for protected self-hosted servers.')),
            ],
            __('Push notifications via ntfy. Each user subscribes to their own topic in their profile.'),
            flat: true
        );
    }

    protected function getSearXngSettings(): Group
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
                    ->nullable(),
                TextInput::make('max_priced_results')
                    ->label('Stop after this many priced results')
                    ->hintIcon(Icons::Help->value, __('Search will stop once this many results with detected prices have been found'))
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->default(SearchService::DEFAULT_MAX_PRICED_RESULTS),
                Select::make('prune_days')
                    ->label('Cache duration')
                    ->required()
                    ->hintIcon(Icons::Help->value, __('How long to keep the parsed search results in the cache'))
                    ->options([
                        1 => '1 day',
                        7 => '7 days',
                        14 => '14 days',
                        30 => '30 days',
                        90 => '90 days',
                        180 => '180 days',
                        365 => '365 days',
                    ])
                    ->default(UrlResearch::DEFAULT_PRUNE_DAYS),
                Select::make('max_pages')
                    ->label('How many pages of results to fetch')
                    ->required()
                    ->hintIcon(Icons::Help->value, __('The more pages you fetch, the longer it will take to search'))
                    ->options(options: [
                        1 => '1 page',
                        2 => '2 pages',
                        3 => '3 pages',
                        4 => '4 pages',
                        5 => '5 pages',
                        10 => '10 pages',
                        20 => '20 pages',
                        50 => '50 pages',
                        100 => '100 pages',
                    ])
                    ->default(SearchService::DEFAULT_MAX_PAGES),
            ],
            new HtmlString('Automatically search for additional products urls via <a href="https://searxng.org/" target="_blank">SearXng</a>'),
            flat: true
        );
    }

    protected function getAiSettings(): Group
    {
        return self::makeSettingsSection(
            'AI',
            self::INTEGRATED_SERVICES_KEY,
            IntegratedServices::Ai->value,
            [
                ...self::aiFeatureProviderSelects(),
                Select::make('default_provider_id')
                    ->label('Default provider')
                    ->live()
                    ->options(fn (Get $get): array => collect($get('providers') ?? [])
                        ->filter(fn ($p): bool => filled($p['id'] ?? null))
                        ->mapWithKeys(fn ($p): array => [$p['id'] => filled($p['name'] ?? null) ? $p['name'] : 'Provider'])
                        ->all()),
                Repeater::make('providers')
                    ->label('Providers')
                    ->addActionLabel('Add provider')
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $state['name'] ?? 'Provider')
                    ->extraItemActions([
                        Action::make('testProvider')
                            ->icon('heroicon-m-signal')
                            ->tooltip('Test this provider (save first)')
                            ->action(function (array $arguments, Repeater $component, AppSettingsPage $livewire): void {
                                $state = $component->getItemState($arguments['item']);
                                $livewire->testProviderById($state['id'] ?? '');
                            }),
                    ])
                    ->schema([
                        Hidden::make('id')->default(fn (): string => (string) Str::ulid()),
                        TextInput::make('name')
                            ->required()
                            ->placeholder('e.g. Local Ollama'),
                        Select::make('type')
                            ->options([
                                AiProvider::OpenAI->value => 'OpenAI',
                                AiProvider::Anthropic->value => 'Anthropic',
                                AiProvider::Ollama->value => 'Ollama',
                                AiProvider::Gemini->value => 'Gemini',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => $get('type') === AiProvider::Ollama->value
                                ? 'e.g. http://localhost:11434'
                                : 'Leave empty to use default.')
                            ->required(fn (Get $get): bool => $get('type') === AiProvider::Ollama->value),
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->helperText('Leave blank to keep the current key.')
                            ->visible(fn (Get $get): bool => $get('type') !== AiProvider::Ollama->value),
                        // Two fields share the 'model' state key, disambiguated by ->key() and
                        // toggled by ->visible() on the provider type: a free-text input for cloud
                        // providers and a searchable dropdown (with refresh) for Ollama. Keep their
                        // visible() closures mutually exclusive so exactly one writes 'model'.
                        TextInput::make('model')
                            ->key('model_text')
                            ->label('Model')
                            ->placeholder('gpt-4.1-mini')
                            ->visible(fn (Get $get): bool => $get('type') !== AiProvider::Ollama->value),
                        Select::make('model')
                            ->key('model_select')
                            ->label('Model')
                            ->native(false)
                            ->searchable()
                            ->placeholder('Refresh to load models')
                            ->visible(fn (Get $get): bool => $get('type') === AiProvider::Ollama->value)
                            ->options(function (AppSettingsPage $livewire, Get $get): array {
                                $models = $livewire->ollamaModels[$get('id')] ?? [];
                                $current = $get('model');

                                if (filled($current) && ! in_array($current, $models, true)) {
                                    $models[] = $current;
                                }

                                return array_combine($models, $models) ?: [];
                            })
                            ->suffixAction(
                                Action::make('refreshOllamaModels')
                                    ->icon('heroicon-m-arrow-path')
                                    ->tooltip('Refresh models from Ollama')
                                    ->action(function (AppSettingsPage $livewire, Get $get): void {
                                        $livewire->refreshOllamaModelsFor($get('id'), $get('base_url'));
                                    }),
                            ),
                        TextInput::make('timeout_seconds')
                            ->label('Timeout seconds')
                            ->helperText('Local models can take ~1 minute to cold-load.')
                            ->numeric()->minValue(1)->default(60),
                        TextInput::make('max_tokens')
                            ->label('Max tokens')
                            ->numeric()->minValue(1)->default(2000),
                        TextInput::make('temperature')
                            ->label('Temperature')
                            ->numeric()->minValue(0)->maxValue(2)->default(0.2),
                    ])
                    ->columns(2),

            ],
            __('Configure one or more AI providers and choose which is used by default.'),
            flat: true,
            cols: 1,
        );
    }

    /**
     * One provider select per AI feature: choose a provider, leave empty for the
     * default, or disable the feature. State-pathed under integrated_services.ai.
     *
     * @return array<int, Select>
     */
    protected static function aiFeatureProviderSelects(): array
    {
        return collect(AiFeature::cases())
            ->map(fn (AiFeature $feature): Select => Select::make('feature_providers.'.$feature->value)
                ->label($feature->label().' provider')
                ->helperText('Leave empty to use default model')
                ->options(fn (Get $get): array => collect($get('providers') ?? [])
                    ->filter(fn ($p): bool => filled($p['id'] ?? null))
                    ->mapWithKeys(fn ($p): array => [$p['id'] => filled($p['name'] ?? null) ? $p['name'] : 'Provider'])
                    ->all() + [IntegrationHelper::FEATURE_DISABLED => 'Disable this feature']))
            ->all();
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
