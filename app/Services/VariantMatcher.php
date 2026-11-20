<?php

namespace App\Services;

use App\Dto\VariantDto;
use App\Dto\VariantFilterDto;
use App\Enums\StockStatus;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Collection;

/**
 * Picks the variants a URL tracks from the variants found on its page, and reduces them
 * to one stock status and price.
 */
class VariantMatcher
{
    /**
     * Normalise an option value for comparison: case-insensitive, and spaces, hyphens and
     * underscores are ignored. So "X-Large", "x large" and "XLarge" are equal, but "Small"
     * never equals "X Small" because values are compared whole.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        return (string) preg_replace('/[\s\-_]+/u', '', $value);
    }

    /**
     * The variants matching a filter: every filter value must equal one of the
     * variant's option values. A pinned id narrows that to one exact variant while the
     * page still lists it. A pinned variant that contradicts the values (eg an id
     * detected on another page) is ignored, so the values decide.
     *
     * @param  Collection<int, VariantDto>  $variants
     * @return Collection<int, VariantDto>
     */
    public static function match(Collection $variants, VariantFilterDto $filter): Collection
    {
        $wanted = array_map(self::normalize(...), $filter->values);

        $byValues = $variants->filter(function (VariantDto $variant) use ($wanted): bool {
            $have = self::comparableValues($variant);

            foreach ($wanted as $value) {
                if (! in_array($value, $have, true)) {
                    return false;
                }
            }

            return true;
        })->values();

        if ($filter->id === null) {
            return $byValues;
        }

        $pinned = ($wanted === [] ? $variants : $byValues)
            ->filter(fn (VariantDto $variant): bool => $variant->id !== null && $variant->id === $filter->id)
            ->values();

        return $pinned->isNotEmpty() || $wanted === [] ? $pinned : $byValues;
    }

    /**
     * Reduce the matching variants to the URL's stock status and price.
     *
     * Several matches (eg "Small" in three colours) count as in stock when any of them
     * is, at the lowest in-stock price. No match counts as out of stock: many stores
     * drop sold out variants from the page entirely. Prices shown as text, eg "£20.00",
     * are read with the store's locale and currency to find the lowest.
     *
     * @param  Collection<int, VariantDto>  $variants
     * @return array{status: StockStatus, price: int|float|string|null, matched: Collection<int, VariantDto>}
     */
    public static function resolve(Collection $variants, VariantFilterDto $filter, ?string $locale = null, ?string $currency = null): array
    {
        $matched = self::match($variants, $filter);

        if ($matched->isEmpty()) {
            return ['status' => StockStatus::OutOfStock, 'price' => null, 'matched' => $matched];
        }

        $inStock = $matched->filter(fn (VariantDto $variant): bool => $variant->status === StockStatus::InStock);

        /** @var VariantDto $best */
        $best = $matched->sortBy(fn (VariantDto $variant): int => $variant->status->getSortOrder())->first();
        $status = $inStock->isNotEmpty() ? StockStatus::InStock : $best->status;

        $pool = $inStock->isNotEmpty() ? $inStock : $matched;

        return ['status' => $status, 'price' => self::lowestPrice($pool, $locale, $currency), 'matched' => $matched];
    }

    /**
     * The lowest price among the variants, as extracted. A price that can't be read as a
     * number is only used when no other price can be.
     *
     * @param  Collection<int, VariantDto>  $variants
     */
    protected static function lowestPrice(Collection $variants, ?string $locale, ?string $currency): int|float|string|null
    {
        $withPrice = $variants->filter(fn (VariantDto $variant): bool => $variant->price !== null && $variant->price !== '');

        $readable = $withPrice
            ->map(fn (VariantDto $variant): array => [$variant->price, CurrencyHelper::toFloat($variant->price, $locale, $currency)])
            ->filter(fn (array $price): bool => $price[1] !== null)
            ->sortBy(fn (array $price): float => (float) $price[1]);

        return $readable->first()[0] ?? $withPrice->first()?->price;
    }

    /**
     * The normalised values a filter can match on: the variant's options, or when it has
     * none, the segments of its label (eg "Small / Black" gives "small" and "black").
     *
     * @return array<int, string>
     */
    protected static function comparableValues(VariantDto $variant): array
    {
        $values = $variant->options;

        if ($values === []) {
            $values = preg_split('~\s*[/,|]\s*|\s+[-–—]\s+~u', $variant->label) ?: [];
            $values[] = $variant->label;
        }

        return array_values(array_unique(array_map(self::normalize(...), $values)));
    }
}
