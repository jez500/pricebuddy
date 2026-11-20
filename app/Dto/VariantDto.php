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
     */
    public function __construct(
        public string $label,
        public array $options = [],
        public StockStatus $status = StockStatus::InStock,
        public ?string $price = null,
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
            price: isset($data['price']) && $data['price'] !== '' ? (string) $data['price'] : null,
            id: isset($data['id']) && $data['id'] !== '' ? (string) $data['id'] : null,
        );
    }

    /**
     * @return array{label: string, options: array<int, string>, status: string, price: ?string, id: ?string}
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
     * @return array{label: string, options: array<int, string>, status: string, price: ?string, id: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
