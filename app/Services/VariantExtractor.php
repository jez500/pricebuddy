<?php

namespace App\Services;

use App\Dto\AvailabilityStrategyDto;
use App\Dto\VariantDto;
use App\Dto\VariantStrategyDto;
use App\Enums\StockStatus;
use App\Enums\VariantSourceType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Finds the product variants (sizes, colours, screen sizes, ...) on a scraped page.
 *
 * Sites expose variants in three broad ways, each handled here:
 *  - Schema.org: a ProductGroup with hasVariant, or a Product with several offers.
 *    Used when the store has no variants strategy, so it needs no configuration.
 *  - JSON: a JSON document in the page (found by selector or regex) or the whole
 *    response, read with dot paths from the store's variants strategy.
 *  - DOM: one element per variant, eg size buttons that are disabled when sold out.
 *
 * Works on the raw response body so it does not depend on the scraper service.
 */
class VariantExtractor
{
    /**
     * Schema.org properties that commonly distinguish product variants.
     */
    protected const array SCHEMA_VARIANT_PROPERTIES = ['size', 'color', 'colour', 'material', 'pattern', 'width', 'model'];

    /**
     * The variants on a page, or null when none can be found.
     *
     * @return Collection<int, VariantDto>|null
     */
    public static function extract(?string $body, ?VariantStrategyDto $strategy = null, ?AvailabilityStrategyDto $availability = null): ?Collection
    {
        if (blank($body)) {
            return null;
        }

        try {
            $variants = match ($strategy->type ?? VariantSourceType::SchemaOrg) {
                VariantSourceType::SchemaOrg => self::fromSchemaOrg($body),
                VariantSourceType::Dom => self::fromDom($body, $strategy),
                default => self::fromJson($body, $strategy, $availability),
            };
        } catch (Throwable) {
            return null;
        }

        $variants = $variants?->filter(fn (VariantDto $variant): bool => $variant->label !== '' || $variant->id !== null)->values();

        return $variants === null || $variants->isEmpty() ? null : $variants;
    }

    /**
     * Read a variant's raw stock value: booleans, counts, schema.org availability, common
     * words ("Sold out", "available") or text matched by the store's availability rules.
     */
    public static function statusFromValue(mixed $raw, ?AvailabilityStrategyDto $availability = null): StockStatus
    {
        if (is_bool($raw)) {
            return $raw ? StockStatus::InStock : StockStatus::OutOfStock;
        }

        if (is_int($raw) || is_float($raw)) {
            return $raw > 0 ? StockStatus::InStock : StockStatus::OutOfStock;
        }

        if (! is_scalar($raw)) {
            return StockStatus::InStock;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return StockStatus::InStock;
        }

        // Normalised, so "In stock", "in-stock" and "InStock" all read the same.
        $word = VariantMatcher::normalize($value);

        if (in_array($word, ['true', 'yes', 'y', 'available', 'instock', 'onlineonly', 'limitedavailability'], true)) {
            return StockStatus::InStock;
        }

        if (in_array($word, ['false', 'no', 'n', 'unavailable', 'notavailable', 'outofstock', 'soldout'], true)) {
            return StockStatus::OutOfStock;
        }

        if (is_numeric($value)) {
            return (float) $value > 0 ? StockStatus::InStock : StockStatus::OutOfStock;
        }

        if (! str_contains($value, 'schema.org') && ($matchConfig = $availability?->matchConfig()) !== null) {
            return StockStatus::matchFromScrapedValue($value, $matchConfig);
        }

        return StockStatus::fromSchemaOrgAvailability($value);
    }

    /***************************************************
     * Schema.org.
     **************************************************/

    /**
     * @return Collection<int, VariantDto>|null
     */
    protected static function fromSchemaOrg(string $body): ?Collection
    {
        $nodes = self::schemaOrgNodes($body);

        // A ProductGroup lists its variants as Products in hasVariant.
        foreach ($nodes as $node) {
            if (self::hasType($node, 'productgroup') && is_array($node['hasVariant'] ?? null)) {
                $variesBy = self::variesBy($node);

                $variants = collect(self::listOf($node['hasVariant']))
                    ->filter(fn ($variant): bool => is_array($variant))
                    ->map(fn (array $variant): VariantDto => self::schemaProductVariant($variant, $variesBy));

                if ($variants->isNotEmpty()) {
                    return $variants->values();
                }
            }
        }

        // A Product may list one offer per variant.
        foreach ($nodes as $node) {
            if (! self::hasType($node, 'product')) {
                continue;
            }

            $offers = $node['offers'] ?? null;

            if (is_array($offers) && self::hasType($offers, 'aggregateoffer')) {
                $offers = $offers['offers'] ?? null;
            }

            if (! is_array($offers) || $offers === []) {
                continue;
            }

            $offers = array_is_list($offers) ? $offers : [$offers];

            $variants = collect($offers)
                ->filter(fn ($offer): bool => is_array($offer))
                ->map(fn (array $offer): ?VariantDto => self::schemaOfferVariant($offer))
                ->filter()
                ->values();

            // Several identifiable offers are a variant list. A single offer only counts
            // when it names variant properties (eg its item's size), because a lone offer
            // with just a name is usually the whole product. This keeps a list that has
            // shrunk to one variant, so a tracked variant that was removed reads as out
            // of stock instead of failing.
            if ($variants->count() >= 2 || $variants->contains(fn (VariantDto $variant): bool => $variant->options !== [])) {
                return $variants;
            }
        }

        return null;
    }

    /**
     * All JSON-LD nodes on the page, flattening top level lists and @graph.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function schemaOrgNodes(string $body): array
    {
        $nodes = [];

        (new Crawler($body))->filter('script[type="application/ld+json"]')->each(function (Crawler $script) use (&$nodes): void {
            $decoded = json_decode(trim($script->text('', false)), true);

            if (! is_array($decoded)) {
                return;
            }

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $nodes[] = $item;

                foreach (self::listOf($item['@graph'] ?? []) as $graphItem) {
                    if (is_array($graphItem)) {
                        $nodes[] = $graphItem;
                    }
                }
            }
        });

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected static function hasType(array $node, string $type): bool
    {
        $types = array_map(
            static fn ($value): string => strtolower(Str::afterLast((string) $value, '/')),
            array_filter((array) ($node['@type'] ?? []), 'is_scalar'),
        );

        return in_array($type, $types, true);
    }

    /**
     * Property names a ProductGroup varies by, eg ["size", "color"].
     *
     * @param  array<string, mixed>  $group
     * @return array<int, string>
     */
    protected static function variesBy(array $group): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => is_scalar($value) ? Str::afterLast((string) $value, '/') : '',
            self::listOf($group['variesBy'] ?? []),
        )));
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<int, string>  $variesBy
     */
    protected static function schemaProductVariant(array $product, array $variesBy): VariantDto
    {
        $options = [];

        foreach (array_unique([...$variesBy, ...self::SCHEMA_VARIANT_PROPERTIES]) as $property) {
            $options = [...$options, ...self::schemaValues($product[$property] ?? null)];
        }

        foreach (self::listOf($product['additionalProperty'] ?? []) as $property) {
            $options = [...$options, ...self::schemaValues(is_array($property) ? ($property['value'] ?? null) : null)];
        }

        $options = array_values(array_unique($options));
        $offer = self::firstOffer($product['offers'] ?? null);
        $name = self::scalarString($product['name'] ?? null);

        return new VariantDto(
            label: $options !== [] ? implode(' / ', $options) : (string) $name,
            options: $options,
            status: StockStatus::fromSchemaOrgAvailability(self::scalarString(data_get($offer, 'availability'))),
            price: self::schemaPrice($offer),
            id: self::scalarString($product['sku'] ?? $product['gtin'] ?? $product['productID'] ?? $product['@id'] ?? null),
        );
    }

    /**
     * An offer is only a variant when it says what it is for, eg its name or item.
     *
     * @param  array<string, mixed>  $offer
     */
    protected static function schemaOfferVariant(array $offer): ?VariantDto
    {
        $item = is_array($offer['itemOffered'] ?? null) ? $offer['itemOffered'] : [];
        $options = [];

        foreach (self::SCHEMA_VARIANT_PROPERTIES as $property) {
            $options = [...$options, ...self::schemaValues($item[$property] ?? null)];
        }

        $options = array_values(array_unique($options));
        $name = self::scalarString($offer['name'] ?? $item['name'] ?? null);
        $id = self::scalarString($offer['sku'] ?? $item['sku'] ?? $offer['gtin'] ?? null);

        if ($options === [] && $name === null) {
            return null;
        }

        return new VariantDto(
            label: $options !== [] ? implode(' / ', $options) : (string) $name,
            options: $options,
            status: StockStatus::fromSchemaOrgAvailability(self::scalarString($offer['availability'] ?? null)),
            price: self::schemaPrice($offer),
            id: $id,
        );
    }

    /**
     * Values of a schema.org property: a string, a list, or an object with a name/value
     * (eg a SizeSpecification).
     *
     * @return array<int, string>
     */
    protected static function schemaValues(mixed $value): array
    {
        $values = [];

        foreach (self::listOf($value) as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['value'] ?? null;
            }

            if (($string = self::scalarString($item)) !== null) {
                $values[] = $string;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function firstOffer(mixed $offers): ?array
    {
        if (! is_array($offers)) {
            return null;
        }

        if (array_is_list($offers)) {
            $offers = $offers[0] ?? null;
        }

        return is_array($offers) ? $offers : null;
    }

    /**
     * Schema.org prices always use "." for decimals, so numeric text is read as a number.
     *
     * @param  array<string, mixed>|null  $offer
     */
    protected static function schemaPrice(?array $offer): int|float|string|null
    {
        return self::price(data_get($offer, 'price', data_get($offer, 'lowPrice', data_get($offer, 'priceSpecification.price'))), null, true);
    }

    /***************************************************
     * JSON.
     **************************************************/

    /**
     * @return Collection<int, VariantDto>|null
     */
    protected static function fromJson(string $body, ?VariantStrategyDto $strategy, ?AvailabilityStrategyDto $availability): ?Collection
    {
        if ($strategy === null) {
            return null;
        }

        $document = self::decodeJson(self::locateJson($body, $strategy));

        if ($document === null) {
            return null;
        }

        $list = $strategy->path ? data_get($document, $strategy->path) : $document;

        if (! is_array($list) || $list === []) {
            return null;
        }

        return collect(array_values($list))
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item) use ($strategy, $availability): VariantDto {
                $options = [];

                foreach ($strategy->options as $path) {
                    $options = [...$options, ...self::stringsAt($item, $path)];
                }

                $options = array_values(array_unique($options));
                $id = $strategy->id ? (self::stringsAt($item, $strategy->id)[0] ?? null) : null;

                return new VariantDto(
                    label: $options !== [] ? implode(' / ', $options) : (string) $id,
                    options: $options,
                    status: $strategy->availability
                        ? self::statusFromValue(data_get($item, $strategy->availability), $availability)
                        : StockStatus::InStock,
                    price: $strategy->price ? self::price(data_get($item, $strategy->price), $strategy->priceDivisor, true) : null,
                    id: $id,
                );
            })
            ->values();
    }

    protected static function locateJson(string $body, VariantStrategyDto $strategy): ?string
    {
        if ($strategy->type === VariantSourceType::Json) {
            return $body;
        }

        if ($strategy->value === null) {
            return null;
        }

        if ($strategy->type === VariantSourceType::Regex) {
            if (@preg_match(ScrapeUrl::ensureRegexDelimiters($strategy->value), $body, $matches) !== 1) {
                return null;
            }

            return $matches[1] ?? $matches[0];
        }

        return self::readNode(new Crawler($body), $strategy->value, false);
    }

    protected static function decodeJson(?string $json): mixed
    {
        if (blank($json)) {
            return null;
        }

        $decoded = json_decode(trim($json), true);

        if ($decoded === null) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Scalar values at a dot path, flattening `*` wildcard results.
     *
     * @param  array<mixed>  $item
     * @return array<int, string>
     */
    protected static function stringsAt(array $item, string $path): array
    {
        $value = data_get($item, $path);
        $values = is_array($value) ? Arr::flatten($value) : [$value];

        return array_values(array_filter(array_map(self::scalarString(...), $values), fn (?string $value): bool => $value !== null));
    }

    /***************************************************
     * DOM.
     **************************************************/

    /**
     * @return Collection<int, VariantDto>|null
     */
    protected static function fromDom(string $body, ?VariantStrategyDto $strategy): ?Collection
    {
        if ($strategy?->value === null) {
            return null;
        }

        $items = (new Crawler($body))->filter($strategy->value);

        if (! $items->count()) {
            return null;
        }

        return collect($items->each(function (Crawler $item) use ($strategy): VariantDto {
            $label = isset($strategy->options[0])
                ? self::readNode($item, $strategy->options[0])
                : self::readNode($item, '');
            $label = (string) $label;

            return new VariantDto(
                label: $label,
                options: $label !== '' ? [$label] : [],
                status: self::domIsOutOfStock($item, $strategy->outOfStock) ? StockStatus::OutOfStock : StockStatus::InStock,
                price: $strategy->price ? self::price(self::readNode($item, $strategy->price), $strategy->priceDivisor, false) : null,
                id: $strategy->id ? self::readNode($item, $strategy->id) : null,
            );
        }))->values();
    }

    /**
     * Whether a variant element is marked unavailable: it (or a child) matches the
     * out_of_stock selector, or has the out_of_stock `|attribute` set to anything but
     * "false".
     */
    protected static function domIsOutOfStock(Crawler $item, ?string $outOfStock): bool
    {
        if ($outOfStock === null) {
            return false;
        }

        if (str_starts_with($outOfStock, ScrapeUrl::SELECTOR_ATTR_DELIMITER)) {
            $value = $item->attr(substr($outOfStock, 1));

            return $value !== null && strtolower(trim($value)) !== 'false';
        }

        return $item->matches($outOfStock) || $item->filter($outOfStock)->count() > 0;
    }

    /**
     * Read text, html or an attribute from a node using the store selector syntax
     * (`selector`, `selector|attribute`, `!selector` for html). An empty selector reads
     * the node itself, eg `|data-size`.
     */
    protected static function readNode(Crawler $node, string $spec, bool $normalizeWhitespace = true): ?string
    {
        $parsed = ScrapeUrl::parseSelector($spec);
        [$selector, $mode] = $parsed;
        $selector = trim($selector);

        $target = $selector === '' ? $node : $node->filter($selector)->first();

        if (! $target->count()) {
            return null;
        }

        $value = match ($mode) {
            'attr' => $target->attr((string) ($parsed[2][0] ?? '')),
            'html' => $target->html(''),
            default => $target->text('', $normalizeWhitespace),
        };

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /***************************************************
     * Helpers.
     **************************************************/

    /**
     * @return array<int, mixed>
     */
    protected static function listOf(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    protected static function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A variant price. Data meant for machines (JSON, schema.org) keeps numbers as numbers,
     * so a store locale that uses "." for thousands can't misread 32.5 as 325. Page text
     * such as "£38.99" or "1.234,50" stays text and is read later with the store's locale.
     * The divisor applies to numbers only, eg prices in cents.
     */
    protected static function price(mixed $value, ?float $divisor, bool $machineReadable): int|float|string|null
    {
        if (is_int($value) || is_float($value)) {
            $number = $value;
        } else {
            $value = self::scalarString($value);

            if ($value === null) {
                return null;
            }

            if (! is_numeric($value) || (! $machineReadable && ! $divisor)) {
                return $value;
            }

            $number = (float) $value;
        }

        return $divisor ? round($number / $divisor, 4) : $number;
    }
}
