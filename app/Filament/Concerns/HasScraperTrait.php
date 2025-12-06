<?php

namespace App\Filament\Concerns;

use App\Enums\Icons;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

trait HasScraperTrait
{
    protected static function makeStrategyInput(string $key, ?string $default = null): array
    {
        return [
            Select::make($key.'.type')
                ->label('Type')
                ->options(ScraperStrategyType::class)
                ->required()
                ->default(ScraperStrategyType::Selector->value)
                ->hintIcon(Icons::Help->value, 'How to get the value')
                ->live(),
            TextInput::make($key.'.value')
                ->label('Value')
                ->default($default)
                ->required()
                ->hintIcon(Icons::Help->value, fn (Get $get) => ScraperStrategyType::getValueHelp($get($key.'.type')))
                ->live(),
            TextInput::make($key.'.prepend')
                ->label('Prepend')
                ->hintIcon(Icons::Help->value, 'Optionally prepend a static value to the extracted value'),
            TextInput::make($key.'.append')
                ->label('Append')
                ->hintIcon(Icons::Help->value, 'Optionally append a static value to the extracted value'),
        ];
    }

    protected static function getScraperSettings(): Section
    {
        return Section::make('Scraper service')->schema([
            Radio::make('settings.scraper_service')
                ->options(ScraperService::class)
                ->descriptions([
                    ScraperService::Http->value => 'Faster and less resource intensive. Use this for JSON strategy',
                    ScraperService::Api->value => 'Slower but good for scraping JavaScript rendered pages',
                ])
                ->reactive()
                ->default(ScraperService::Http),

            Textarea::make('settings.scraper_service_settings')
                ->label('Settings')
                ->hint(new HtmlString('One option per line. <a href="https://github.com/jez500/seleniumbase-scrapper#api-endpoints" target="_blank">Read docs</a>'))
                ->hidden(fn (Get $get) => $get('settings.scraper_service') !== ScraperService::Api->value)
                ->rows(4)
                ->placeholder("device=Desktop Firefox\nsleep=1000"),
        ])->description('Advanced scraper service settings')->columns(2);
    }
}
