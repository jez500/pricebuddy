<?php

namespace App\Filament\Resources;

use App\Enums\AiFeature;
use App\Enums\Icons;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Enums\StockStatus;
use App\Filament\Concerns\HasScraperTrait;
use App\Filament\Pages\AppSettingsPage;
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\Store;
use App\Models\Url;
use App\Providers\Filament\AdminPanelProvider;
use App\Rules\StoreUrl;
use App\Services\Helpers\IntegrationHelper;
use Filament\Forms;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class StoreResource extends Resource
{
    use HasScraperTrait;

    public const array DEFAULT_SELECTORS = [
        'title' => 'meta[property=og:title]|content',
        'price' => 'meta[property=og:price:amount]|content',
        'image' => 'meta[property=og:image]|content',
    ];

    public const string API_GROUP = 'Store';

    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Basics')->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->hintIcon(Icons::Help->value, 'The name of the store')
                        ->required(),
                ])
                    ->columns(1)
                    ->description(__('Stores are shared between all users in :name', ['name' => config('app.name')]))
                    ->live(),

                Section::make('Domains')->schema([
                    Forms\Components\Repeater::make('domains')
                        ->schema([
                            TextInput::make('domain')->label('Domain'),
                        ])->required(),
                ])
                    ->description('What domains does this store apply to'),

                Forms\Components\Group::make([
                    Section::make('Title strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('title', self::DEFAULT_SELECTORS['title']))->columns(2),
                    ])->description('How to get the product title'),
                    Section::make('Price strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('price', self::DEFAULT_SELECTORS['price']))->columns(2),
                    ])->description('How to get the product price'),
                    Section::make('Image strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('image', self::DEFAULT_SELECTORS['image']))->columns(2),
                    ])->description('How to get the product image'),
                    Section::make('Availability strategy')->schema([
                        Forms\Components\Group::make(self::makeStrategyInput('availability', required: false))->columns(2),
                        Section::make('Match values')
                            ->schema(
                                collect(StockStatus::nonInStockCases())->map(
                                    fn (StockStatus $status) => Forms\Components\Group::make([
                                        Forms\Components\Select::make('availability.match.'.$status->value.'.type')
                                            ->label('Type')
                                            ->options([
                                                'match' => 'Exact match',
                                                'regex' => 'Regex',
                                            ])
                                            ->default('match')
                                            ->afterStateHydrated(fn (Forms\Components\Select $component, ?string $state) => $component->state($state ?? 'match'))
                                            ->required(),
                                        TextInput::make('availability.match.'.$status->value.'.value')
                                            ->label($status->getLabel())
                                            ->hintIcon($status->getIcon(), 'If the scraped text matches this value, the product will be marked as "'.$status->getLabel().'"'),
                                    ])->columns(2)
                                )->toArray()
                            )
                            ->description('Map scraped text values to stock statuses. Order is priority (first match wins).')
                            ->columns(1)
                            ->collapsed(fn (Get $get): bool => empty(array_filter(
                                (array) $get('availability.match'),
                                fn ($entry, $key) => $key !== 'default' && (is_array($entry) ? ($entry['value'] ?? '') !== '' : ($entry !== '' && $entry !== null)),
                                ARRAY_FILTER_USE_BOTH,
                            )))
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value),
                        Forms\Components\Select::make('availability.match.default')
                            ->label('Default status')
                            ->options(StockStatus::class)
                            ->default(StockStatus::InStock->value)
                            ->afterStateHydrated(fn (Forms\Components\Select $component, ?string $state) => $component->state($state ?? StockStatus::InStock->value))
                            ->required()
                            ->hintIcon(Icons::Help->value, 'The status to use when the scraped text does not match any of the values above')
                            ->hidden(fn (Get $get): bool => $get('availability.type') === ScraperStrategyType::SchemaOrg->value),
                    ])->description('Optional: a selector that matches product availability.')
                        ->collapsed(fn (Get $get): bool => ($get('availability.value') ?? '') === ''),
                ])
                    ->label('Scrape Strategy')
                    ->statePath('scrape_strategy'),

                self::getScraperSettings(),

                Section::make('Locale')
                    ->description(__('Override region and locale settings for this store'))
                    ->columns(2)
                    ->schema(AppSettingsPage::getLocaleFormFields('settings.locale_settings')),

                Section::make('Cookies')->schema([
                    TextInput::make('cookies')
                        ->label('Cookies')
                        ->hintIcon(Icons::Help->value, 'Any cookies to include in scrape requests for this store. Format as you would in an HTTP header, e.g. "cookie1=value; cookie2=value"'),
                ])->description('Optional cookies to include in scrape requests for this store'),

                Section::make('Notes')->schema([
                    Forms\Components\RichEditor::make('notes')
                        ->hiddenLabel(true),
                ])->description('Additional notes regarding this store and how to scrape its content'),
            ])
            ->columns(1);
    }

    public static function testForm(Form $form, Store $store): Form
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Url> $shortcutUrls */
        $shortcutUrls = $store->urls()
            ->with('product')
            ->whereHas('product')
            ->get()
            ->unique('product_id')
            ->take(5);

        return $form
            ->columns(1)
            ->schema(array_values(array_filter([
                $shortcutUrls->isNotEmpty()
                    ? Actions::make(
                        $shortcutUrls->map(fn (Url $url): FormAction => FormAction::make('product_'.$url->getKey())
                            ->label($url->product->title)
                            ->action(fn (Get $get, EditStore $livewire) => $livewire->runScrape($url->url, $get('test_scraper')))
                        )->all()
                    )->label('Existing products')->key('product_shortcuts')
                    : null,

                TextInput::make('test_url')
                    ->label($shortcutUrls->isNotEmpty() ? 'Or product URL' : 'Product URL')
                    ->hintIcon(Icons::Help->value, 'The URL to scrape')
                    ->placeholder(fn (): ?string => filled($host = data_get($store, 'domains.0.domain'))
                        ? 'https://'.$host.'/example-product'
                        : null)
                    ->default(fn (): string => (string) data_get($store, 'settings.test_url', ''))
                    ->required()
                    ->rules([new StoreUrl])
                    ->suffixAction(
                        FormAction::make('scrape')
                            ->label('Test url scrape')
                            ->icon(Icons::Search->value)
                            ->action(function (Get $get, EditStore $livewire): void {
                                $url = (string) $get('test_url');

                                if (filled($url)) {
                                    $livewire->runScrape($url, $get('test_scraper'));
                                }
                            })
                    ),

                Section::make('Results')
                    ->description('What we could find')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->testScrapeResult))
                    ->headerActions([
                        FormAction::make('compareWithAi')
                            ->label('Compare with AI')
                            ->icon('heroicon-m-sparkles')
                            ->visible(fn (): bool => IntegrationHelper::isAiEnabled())
                            ->action(fn (EditStore $livewire) => $livewire->compareWithAi()),
                        FormAction::make('healWithAi')
                            ->label('Heal with AI')
                            ->icon('heroicon-m-wrench-screwdriver')
                            ->visible(fn (): bool => IntegrationHelper::isFeatureEnabled(AiFeature::Healing))
                            ->action(fn (EditStore $livewire) => $livewire->previewSelfHeal()),
                    ])
                    ->schema([
                        View::make('filament.resources.store-resource.test-results')
                            ->viewData(fn (EditStore $livewire): array => [
                                'scrape' => $livewire->testScrapeResult,
                                'ai' => $livewire->testAiResult,
                                'record' => $livewire->buildUnsavedStore(),
                            ]),

                        Select::make('test_scraper')
                            ->label('Change scraper')
                            ->options(ScraperService::class)
                            ->selectablePlaceholder(false)
                            ->afterStateHydrated(fn (Select $component, EditStore $livewire) => $component->state(
                                $component->getState()
                                    ?: $livewire->testScraper
                                    ?: $livewire->buildUnsavedStore()->scraper_service
                                    ?: ScraperService::Http->value
                            ))
                            ->live()
                            ->afterStateUpdated(function (EditStore $livewire, ?string $state): void {
                                if (filled($livewire->testUrl) && filled($state)) {
                                    $livewire->runScrape($livewire->testUrl, $state);
                                }
                            }),
                    ]),

                Section::make('AI healing proposal')
                    ->description('Proposed selectors — review, then apply to the form')
                    ->extraAttributes(['class' => 'mt-4'])
                    ->visible(fn (EditStore $livewire): bool => filled($livewire->healPreview))
                    ->headerActions([
                        FormAction::make('applySelfHeal')
                            ->label('Apply to form')
                            ->icon('heroicon-m-check')
                            ->action(fn (EditStore $livewire) => $livewire->applySelfHeal()),
                        FormAction::make('discardSelfHeal')
                            ->label('Discard')
                            ->color('gray')
                            ->action(fn (EditStore $livewire) => $livewire->discardSelfHeal()),
                    ])
                    ->schema([
                        View::make('filament.resources.store-resource.heal-preview')
                            ->viewData(fn (EditStore $livewire): array => ['preview' => $livewire->healPreview]),
                    ]),
            ])));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Split::make([
                        TextColumn::make('name')
                            ->searchable()
                            ->sortable()
                            ->weight(FontWeight::Bold)
                            ->description(fn (Store $record): HtmlString => $record->domains_html),
                    ]),
                    TextColumn::make('products_count')
                        ->sortable()
                        ->formatStateUsing(fn (string $state) => $state.' products')
                        ->extraAttributes(['class' => 'min-w-36 md:flex md:justify-end pr-4'])
                        ->grow(false),
                    TextColumn::make('settings.scraper_service')
                        ->label('Scraper')
                        ->badge()
                        ->sortable()
                        ->extraAttributes(['class' => 'min-w-16'])
                        ->formatStateUsing(fn (string $state) => strtoupper($state))
                        ->color(fn (Store $record): array => ScraperService::tryFrom($record->scraper_service)->getColor())
                        ->grow(false),
                ])->from('sm'),

            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('settings->scraper_service')
                    ->options(ScraperService::class)
                    ->label('Scraper'),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('products');
            });
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
