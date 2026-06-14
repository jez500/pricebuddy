<?php

namespace App\Dto;

use App\Enums\ScraperStrategyType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One scrape-strategy slot (title, price, image, description) — how to extract a
 * single field plus optional static prepend/append decoration.
 *
 * @implements Arrayable<string, string>
 */
class StandardStrategyDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public ScraperStrategyType $type,
        public ?string $value = null,
        public ?string $prepend = null,
        public ?string $append = null,
    ) {}

    /**
     * Build from a stored strategy array, or null when the type is missing/invalid
     * (the field is then treated as unconfigured). Blank strings normalize to null.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $type = ScraperStrategyType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        return new self(
            type: $type,
            value: self::nullableString($data['value'] ?? null),
            prepend: self::nullableString($data['prepend'] ?? null),
            append: self::nullableString($data['append'] ?? null),
        );
    }

    protected static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type->value];

        if ($this->value !== null && $this->value !== '') {
            $out['value'] = $this->value;
        }

        if ($this->prepend !== null && $this->prepend !== '') {
            $out['prepend'] = $this->prepend;
        }

        if ($this->append !== null && $this->append !== '') {
            $out['append'] = $this->append;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
