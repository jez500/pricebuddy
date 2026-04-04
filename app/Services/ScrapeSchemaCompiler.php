<?php

namespace App\Services;

use App\Dto\Scraping\FieldExtractionDto;
use App\Dto\Scraping\ScrapeSchemaDto;
use App\Exceptions\ScrapeSchemaValidationException;
use Illuminate\Support\Collection;

class ScrapeSchemaCompiler
{
    public function __construct(
        protected ?ScrapeSchemaValidator $validator = null,
    ) {
        $this->validator ??= new ScrapeSchemaValidator;
    }

    /**
     * @param  array<string, mixed>|ScrapeSchemaDto  $schema
     * @return array<string, array{value: ?string, match: ?string, errors?: array<int, string>}>
     */
    public function fromDto(array|ScrapeSchemaDto $schema, object $scraper): array
    {
        $dto = $schema instanceof ScrapeSchemaDto ? $schema : ScrapeSchemaDto::fromArray($schema);
        $output = [];

        foreach ($dto->fields as $fieldName => $field) {
            ['field' => $validatedField, 'errors' => $errors] = $this->validateField($fieldName, $field);

            if ($validatedField === null) {
                $output[$fieldName] = [
                    'value' => null,
                    'match' => null,
                    'errors' => $errors,
                ];

                continue;
            }

            $output[$fieldName] = $this->compileField($fieldName, $validatedField, $scraper);
        }

        return $output;
    }

    /**
     * @return array{value: ?string, match: ?string}
     */
    public function compileField(string $fieldName, FieldExtractionDto $field, object $scraper): array
    {
        try {
            $value = $this->extractValue($fieldName, $field, $scraper);
        } catch (\Throwable) {
            $value = null;
        }

        $value = $this->applyTransforms($value, $field);

        return [
            'value' => $value,
            'match' => $field->match?->resolve($value),
        ];
    }

    /**
     * @return array{field: ?FieldExtractionDto, errors: array<int, string>}
     */
    protected function validateField(string $fieldName, FieldExtractionDto $field): array
    {
        try {
            $schema = $this->validator->validate(
                ScrapeSchemaDto::fromArray([$fieldName => $field])
            );

            return [
                'field' => $schema->fields[$fieldName] ?? null,
                'errors' => [],
            ];
        } catch (ScrapeSchemaValidationException $exception) {
            return [
                'field' => null,
                'errors' => $exception->errors(),
            ];
        }
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
            : [$type === 'regex'
                ? $this->wrapRegex((string) $field->value)
                : (string) $field->value
            ];

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

    protected function wrapRegex(string $pattern): string
    {
        return '~'.$this->escapeRegexDelimiter($pattern).'~i';
    }

    protected function escapeRegexDelimiter(string $pattern, string $delimiter = '~'): string
    {
        $escaped = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === $delimiter) {
                $backslashes = 0;

                for ($offset = $index - 1; $offset >= 0 && $pattern[$offset] === '\\'; $offset--) {
                    $backslashes++;
                }

                if ($backslashes % 2 === 0) {
                    $escaped .= '\\';
                }
            }

            $escaped .= $character;
        }

        return $escaped;
    }
}
