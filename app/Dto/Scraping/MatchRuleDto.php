<?php

namespace App\Dto\Scraping;

use JsonSerializable;

class MatchRuleDto implements JsonSerializable
{
    public function __construct(
        public string $type,
        public mixed $value = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: strtolower((string) data_get($data, 'type', 'match')),
            value: data_get($data, 'value'),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
