<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ScraperStrategyType: string implements HasDescription, HasLabel
{
    case Selector = 'selector';

    case xPath = 'xpath';

    case Regex = 'regex';

    case Json = 'json';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Selector => 'CSS Selector',
            self::xPath => 'XPath',
            self::Regex => 'Regex',
            self::Json => 'JSON path',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Selector => 'Use CSS selectors to extract a value from a HTML document.',
            self::xPath => 'Use XPath to extract a value from a XML or HTML document.',
            self::Regex => 'Use regular expressions to extract a value from any document.',
            self::Json => 'Use JSON path to extract data from a JSON document.',
        };
    }

    public static function getValueHelp(string $type): string
    {
        return match ($type) {
            self::Selector->value => 'CSS selector to get the value. Use |attribute_name to get an attribute value instead of the element content',
            self::xPath->value => 'XPath expression to get the value. Use @attribute for attributes, text() for text content',
            self::Regex->value => 'Regex pattern to get the value. Enclose the value in () to get the value',
            self::Json->value => 'JSON path to get the value. Use dot notation to get nested values',
            default => ''
        };
    }
}
