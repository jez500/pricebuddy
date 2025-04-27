<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Jez500\WebScraperForLaravel\Enums\ScraperServicesEnum as BaseScraperService;

enum ScraperService: string implements HasColor, HasDescription, HasLabel
{
    case Http = BaseScraperService::Http->value;

    case Api = BaseScraperService::Api->value;

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'Curl based HTTP request',
            self::Api => 'Browser based request',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Http => 'Faster and and less resource intensive',
            self::Api => 'Slower but good for scraping JavaScript rendered pages',
        };
    }

    public function getColor(): array
    {
        return match ($this) {
            self::Http => Color::Blue,
            self::Api => Color::Pink,
        };
    }
}
