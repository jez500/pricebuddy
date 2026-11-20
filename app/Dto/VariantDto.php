<?php

namespace App\Dto;

use App\Enums\StockStatus;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One product variant found on a page, eg size "Small" that is out of stock at 38.99.
 *
 * @implements Arrayable<string, mixed>
 */
class VariantDto implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $options  the variant's option values, eg ["Small", "Black"]
     * @param  int|float|string|null  $price  a number from JSON or schema.org data, or page text such as "£38.99"
     */
    public function __construct(
        public string $label,
        public array $options = [],
        public StockStatus $status = StockStatus::InStock,
        public int|float|string|null $price = null,
        public ?string $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? null;

        return new self(
            label: (string) ($data['label'] ?? ''),
            options: array_values(array_map('strval', (array) ($data['options'] ?? []))),
            status: $status instanceof StockStatus ? $status : (StockStatus::tryFrom((string) $status) ?? StockStatus::InStock),
            price: self::priceFrom($data['price'] ?? null),
            id: isset($data['id']) && $data['id'] !== '' ? (string) $data['id'] : null,
        );
    }

    /**
     * A price as extracted: a number from JSON or schema.org data, or text from the page
     * such as "£38.99" that is read later with the store's locale.
     */
    protected static function priceFrom(mixed $price): int|float|string|null
    {
        if (is_int($price) || is_float($price)) {
            return $price;
        }

        return is_scalar($price) && $price !== '' && ! is_bool($price) ? (string) $price : null;
    }

    /**
     * @return array{label: string, options: array<int, string>, status: string, price: int|float|string|null, id: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'options' => $this->options,
            'status' => $this->status->value,
            'price' => $this->price,
            'id' => $this->id,
        ];
    }

    /**
     * @return array{label: string, options: array<int, string>, status: string, price: int|float|string|null, id: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
