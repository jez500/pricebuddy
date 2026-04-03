<?php

namespace App\Dto\Scraping;

use JsonSerializable;

class ScrapeSchemaDto implements JsonSerializable
{
    /**
     * @param  array<string, FieldExtractionDto>  $fields
     */
    public function __construct(
        public array $fields = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $fields = [];
        $source = is_array(data_get($data, 'fields')) ? data_get($data, 'fields') : $data;

        foreach ($source as $field => $definition) {
            if (! is_string($field)) {
                continue;
            }

            $fields[$field] = $definition instanceof FieldExtractionDto
                ? $definition
                : FieldExtractionDto::fromArray(is_array($definition) ? $definition : []);
        }

        return new self($fields);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray(is_array($decoded) ? $decoded : []);
    }

    public function toArray(): array
    {
        return array_map(
            fn (FieldExtractionDto $field) => $field->toArray(),
            $this->fields,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
