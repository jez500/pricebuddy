<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SchemaOrgService
{
    /**
     * Parse product data from schema.org JSON-LD.
     *
     * Assumes $collection is a collection of schema.org JSON-LD. The one we need has
     *
     * @type = Product. From there, extract out data for the field we want.
     */
    public static function parseSchemaOrg(Collection $collection, string $field): ?string
    {
        // Match the Product node case-insensitively, and tolerate an array @type
        // (e.g. ["Product", "Thing"]). Some sites use a lowercase "product" type.
        $schema = $collection->first(function ($item): bool {
            $types = array_map(
                static fn ($type): string => strtolower((string) $type),
                (array) data_get($item, '@type'),
            );

            return in_array('product', $types, true);
        });

        if (! $schema) {
            return null;
        }

        return match ($field) {
            // Get title from name.
            'title' => data_get($schema, 'name'),
            // Full description.
            'description' => data_get($schema, 'description'),
            // First try for lowest, then price, finally priceSpecification price.
            'price' => data_get($schema, 'offers.lowPrice', data_get($schema, 'offers.price', data_get($schema, 'offers.0.price', data_get($schema, 'offers.priceSpecification.price')))),
            // Currency.
            'price_currency' => data_get($schema, 'offers.priceCurrency', data_get($schema, 'offers.0.priceCurrency', data_get($schema, 'offers.priceSpecification.priceCurrency', 'USD'))),
            // Image should be a string, sometimes array of strings.
            'image' => is_string(data_get($schema, 'image'))
                ? data_get($schema, 'image')
                : (is_array(data_get($schema, 'image')) ? data_get($schema, 'image.0') : null),
            // Availability should be a string, sometimes array of strings.
            'availability' => is_string(data_get($schema, 'offers.availability', data_get($schema, 'offers.0.availability')))
                ? data_get($schema, 'offers.availability', data_get($schema, 'offers.0.availability'))
                : (is_string(data_get($schema, 'offers.availability.0', data_get($schema, 'offers.0.availability.0')))
                    ? data_get($schema, 'offers.availability.0', data_get($schema, 'offers.0.availability.0'))
                    : null),
            default => null
        };
    }
}
