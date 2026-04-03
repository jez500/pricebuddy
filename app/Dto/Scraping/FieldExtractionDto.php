<?php

namespace App\Dto\Scraping;

use JsonSerializable;

class FieldExtractionDto implements JsonSerializable
{
    public const array SUPPORTED_TYPES = [
        'selector',
        'css',
        'xpath',
        'regex',
        'json',
        'schema_org',
    ];

    public function __construct(
        public string $type,
        public mixed $value = null,
        public mixed $prepend = null,
        public mixed $append = null,
        public ?MatchDefinitionDto $match = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) data_get($data, 'type', ''),
            value: data_get($data, 'value'),
            prepend: data_get($data, 'prepend'),
            append: data_get($data, 'append'),
            match: is_array(data_get($data, 'match'))
                ? MatchDefinitionDto::fromArray(data_get($data, 'match'))
                : null,
        );
    }

    public function normalizedType(): string
    {
        $type = strtolower($this->type);

        return $type === 'css' ? 'selector' : $type;
    }

    public function usesValue(): bool
    {
        return $this->normalizedType() !== 'schema_org';
    }

    public function toArray(): array
    {
        $output = [
            'type' => $this->type,
            'value' => $this->value,
            'prepend' => $this->prepend,
            'append' => $this->append,
        ];

        if ($this->match !== null) {
            $output['match'] = $this->match->toArray();
        }

        return $output;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
