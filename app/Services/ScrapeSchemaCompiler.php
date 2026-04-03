<?php

namespace App\Services;

use App\Dto\Scraping\FieldExtractionDto;
use App\Dto\Scraping\ScrapeSchemaDto;
use Illuminate\Support\Collection;

class ScrapeSchemaCompiler
{
    public function __construct(
        protected ?ScrapeSchemaValidator $validator = null,
    ) {
        $this->validator ??= new ScrapeSchemaValidator();
    }

    /**
     * @param  array<string, mixed>|ScrapeSchemaDto  $schema
     * @return array<string, array{value: ?string, match: ?string}>
     */
    public function fromDto(array|ScrapeSchemaDto $schema, object $scraper): array
    {
        $dto = $this->validator->validate($schema);
        $output = [];

        foreach ($dto->fields as $fieldName => $field) {
            $output[$fieldName] = $this->compileField($fieldName, $field, $scraper);
        }

        return $output;
    }

    /**
     * @return array{value: ?string, match: ?string}
     */
    public function compileField(string $fieldName, FieldExtractionDto $field, object $scraper): array
    {
        $value = $this->extractValue($fieldName, $field, $scraper);
        $value = $this->applyTransforms($value, $field);

        return [
            'value' => $value,
            'match' => $field->match?->resolve($value),
        ];
    }

    protected function extractValue(string $fieldName, FieldExtractionDto $field, object $scraper): ?string
    {
        $type = $field->normalizedType();

        if ($type === 'schema_org') {
            return SchemaOrgService::parseSchemaOrg($this->getSchemaOrgCollection($scraper), $fieldName);
        }

        $method = ScrapeUrl::getMethodFromType($type);
        $arguments = $type === 'selector'
            ? ScrapeUrl::parseSelector((string) $field->value)
            : [(string) $field->value];

        $result = call_user_func_array([$scraper, $method], $arguments);

        if ($result instanceof Collection || (is_object($result) && method_exists($result, 'first'))) {
            $result = $result->first();
        }

        return is_string($result) ? $result : (is_scalar($result) ? (string) $result : null);
    }

    protected function applyTransforms(?string $value, FieldExtractionDto $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = ($field->prepend ?? '').$value.($field->append ?? '');

        return $value === '' ? null : $value;
    }

    protected function getSchemaOrgCollection(object $scraper): Collection
    {
        $schema = $scraper->getSchemaOrg();

        return $schema instanceof Collection ? $schema : collect($schema);
    }
}
