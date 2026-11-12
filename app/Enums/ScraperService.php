<?php

namespace App\Enums;

use App\Services\Scrapers\ScavioWebScraper;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Jez500\WebScraperForLaravel\Enums\ScraperServicesEnum as BaseScraperService;

enum ScraperService: string implements HasColor, HasDescription, HasLabel
{
    case Http = BaseScraperService::Http->value;

    case Api = BaseScraperService::Api->value;

    case Scavio = 'scavio';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Services that can be picked in a form. Scavio is only offered once SCAVIO_API_KEY
     * is set, and never where a search page rather than a product page is fetched.
     *
     * @return array<string, string>
     */
    public static function options(bool $withScavio = true): array
    {
        return collect(self::cases())
            ->reject(fn (self $service): bool => $service === self::Scavio
                && (! $withScavio || ! ScavioWebScraper::isConfigured()))
            ->mapWithKeys(fn (self $service): array => [$service->value => $service->getLabel()])
            ->all();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'Curl based HTTP request',
            self::Api => 'Browser based request',
            self::Scavio => 'Scavio API',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Http => 'Faster and and less resource intensive',
            self::Api => 'Slower but good for scraping JavaScript rendered pages',
            self::Scavio => 'Product data from the Scavio API for Amazon and Walmart, needs SCAVIO_API_KEY',
        };
    }

    public function getColor(): array
    {
        return match ($this) {
            self::Http => Color::Blue,
            self::Api => Color::Pink,
            self::Scavio => Color::Emerald,
        };
    }
}
