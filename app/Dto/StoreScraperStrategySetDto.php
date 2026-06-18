<?php

namespace App\Dto;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The full set of scrape strategies stored on a Store's scrape_strategy column.
 *
 * @implements Arrayable<string, array<string, mixed>>
 */
class StoreScraperStrategySetDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?StandardStrategyDto $title = null,
        public ?StandardStrategyDto $price = null,
        public ?StandardStrategyDto $image = null,
        public ?StandardStrategyDto $description = null,
        public ?AvailabilityStrategyDto $availability = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            title: StandardStrategyDto::fromArray(self::slot($data, 'title')),
            price: StandardStrategyDto::fromArray(self::slot($data, 'price')),
            image: StandardStrategyDto::fromArray(self::slot($data, 'image')),
            description: StandardStrategyDto::fromArray(self::slot($data, 'description')),
            availability: AvailabilityStrategyDto::fromArray(self::slot($data, 'availability')),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected static function slot(array $data, string $key): ?array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];

        foreach (['title', 'price', 'image', 'description', 'availability'] as $key) {
            if ($this->{$key} !== null) {
                $out[$key] = $this->{$key}->toArray();
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
