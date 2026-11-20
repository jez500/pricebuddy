<?php

namespace App\Services;

use App\Dto\VariantDto;
use App\Dto\VariantFilterDto;
use App\Enums\StockStatus;
use Illuminate\Support\Collection;

/**
 * Picks the variants a URL tracks from the variants found on its page, and reduces them
 * to one stock status and price.
 */
class VariantMatcher
{
    /**
     * Normalise an option value for comparison: case-insensitive, "-" / "_" read as a
     * space and whitespace collapsed. So "X-Large" equals "x large", but "Small" never
     * equals "X Small" because values are compared whole.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        $value = (string) preg_replace('/[-_]+/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * The variants matching a filter. A pinned id wins while the page still lists it;
     * otherwise every filter value must equal one of the variant's option values.
     *
     * @param  Collection<int, VariantDto>  $variants
     * @return Collection<int, VariantDto>
     */
    public static function match(Collection $variants, VariantFilterDto $filter): Collection
    {
        if ($filter->id !== null) {
            $pinned = $variants->filter(fn (VariantDto $variant): bool => $variant->id !== null && $variant->id === $filter->id);

            if ($pinned->isNotEmpty() || $filter->values === []) {
                return $pinned->values();
            }
        }

        $wanted = array_map(self::normalize(...), $filter->values);

        return $variants->filter(function (VariantDto $variant) use ($wanted): bool {
            $have = self::comparableValues($variant);

            foreach ($wanted as $value) {
                if (! in_array($value, $have, true)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Reduce the matching variants to the URL's stock status and price.
     *
     * Several matches (eg "Small" in three colours) count as in stock when any of them
     * is, at the lowest in-stock price. No match counts as out of stock: many stores
     * drop sold out variants from the page entirely.
     *
     * @param  Collection<int, VariantDto>  $variants
     * @return array{status: StockStatus, price: ?string, matched: Collection<int, VariantDto>}
     */
    public static function resolve(Collection $variants, VariantFilterDto $filter): array
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
        $withPrice = $pool->filter(fn (VariantDto $variant): bool => $variant->price !== null && $variant->price !== '');

        $price = $withPrice
            ->filter(fn (VariantDto $variant): bool => is_numeric($variant->price))
            ->sortBy(fn (VariantDto $variant): float => (float) $variant->price)
            ->first()->price
            ?? $withPrice->first()->price;

        return ['status' => $status, 'price' => $price, 'matched' => $matched];
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
