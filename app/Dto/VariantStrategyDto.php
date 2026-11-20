<?php

namespace App\Dto;

use App\Enums\VariantSourceType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * How a store exposes product variants. Stored as the `variants` slot of a store's
 * scrape_strategy.
 *
 * JSON sources (selector, regex, json) locate a JSON document, then use dot paths:
 * `path` to the list of variants, and `options`, `availability`, `price` and `id`
 * inside each variant. `*` wildcards are allowed, eg `options.*.value.*.label`.
 *
 * The dom source treats each element matching `value` as a variant. `options` is a
 * selector (or `|attribute`) inside it for the label, `out_of_stock` a selector or
 * `|attribute` that marks it unavailable, and `price` / `id` optional selectors.
 *
 * @implements Arrayable<string, mixed>
 */
class VariantStrategyDto implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $options
     */
    public function __construct(
        public VariantSourceType $type,
        public ?string $value = null,
        public ?string $path = null,
        public array $options = [],
        public ?string $availability = null,
        public ?string $price = null,
        public ?string $id = null,
        public ?string $outOfStock = null,
        public ?float $priceDivisor = null,
    ) {}

    /**
     * Build from a stored strategy array, or null when the type is missing/invalid.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $type = VariantSourceType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $options = $data['options'] ?? [];

        if (is_string($options)) {
            $options = preg_split('/[\r\n,]+/', $options) ?: [];
        }

        $options = array_values(array_filter(
            array_map(static fn ($option): string => is_scalar($option) ? trim((string) $option) : '', is_array($options) ? $options : []),
            static fn (string $option): bool => $option !== '',
        ));

        $divisor = $data['price_divisor'] ?? null;
        $divisor = is_numeric($divisor) && (float) $divisor > 0 ? (float) $divisor : null;

        return new self(
            type: $type,
            value: self::nullableString($data['value'] ?? null),
            path: self::nullableString($data['path'] ?? null),
            options: $options,
            availability: self::nullableString($data['availability'] ?? null),
            price: self::nullableString($data['price'] ?? null),
            id: self::nullableString($data['id'] ?? null),
            outOfStock: self::nullableString($data['out_of_stock'] ?? null),
            priceDivisor: $divisor,
        );
    }

    protected static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'value' => $this->value,
            'path' => $this->path,
            'options' => $this->options,
            'availability' => $this->availability,
            'price' => $this->price,
            'id' => $this->id,
            'out_of_stock' => $this->outOfStock,
            'price_divisor' => $this->priceDivisor,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
