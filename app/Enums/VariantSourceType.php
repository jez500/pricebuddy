<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a store's product variants (sizes, colours, screen sizes, ...) are found on a page.
 */
enum VariantSourceType: string implements HasDescription, HasLabel
{
    case SchemaOrg = 'schema_org';

    case Selector = 'selector';

    case Regex = 'regex';

    case Json = 'json';

    case Dom = 'dom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SchemaOrg => 'Schema.org metadata',
            self::Selector => 'JSON in the page (CSS selector)',
            self::Regex => 'JSON in the page (Regex)',
            self::Json => 'JSON response',
            self::Dom => 'Page elements',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SchemaOrg => 'Read variants from Schema.org ProductGroup/hasVariant or a list of offers. No extra configuration needed.',
            self::Selector => 'Find a JSON blob in the page with a CSS selector, eg script#__NEXT_DATA__ or form.variations_form|data-product_variations.',
            self::Regex => 'Find a JSON blob in the page with a regex. Enclose the JSON in () to capture it.',
            self::Json => 'The whole response is JSON, eg a store API.',
            self::Dom => 'Each variant is an element on the page, eg a size button that is disabled when sold out.',
        };
    }

    /**
     * Whether this source reads a JSON document (and therefore uses dot paths).
     */
    public function isJson(): bool
    {
        return in_array($this, [self::Selector, self::Regex, self::Json], true);
    }
}
