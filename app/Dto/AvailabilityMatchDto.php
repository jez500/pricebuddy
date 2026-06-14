<?php

namespace App\Dto;

use App\Enums\AvailabilityMatchType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One per-status availability match rule (e.g. out_of_stock => "Sold out").
 *
 * @implements Arrayable<string, string>
 */
class AvailabilityMatchDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public AvailabilityMatchType $type,
        public string $value,
    ) {}

    /**
     * Build from a stored match entry, or null when there is no usable value.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $value = $data['value'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return new self(
            type: AvailabilityMatchType::tryFrom((string) ($data['type'] ?? 'match')) ?? AvailabilityMatchType::Match,
            value: (string) $value,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
