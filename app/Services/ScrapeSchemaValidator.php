<?php

namespace App\Services;

use App\Dto\Scraping\FieldExtractionDto;
use App\Dto\Scraping\MatchDefinitionDto;
use App\Dto\Scraping\MatchRuleDto;
use App\Dto\Scraping\ScrapeSchemaDto;
use App\Exceptions\ScrapeSchemaValidationException;

class ScrapeSchemaValidator
{
    public function validate(array|ScrapeSchemaDto $schema): ScrapeSchemaDto
    {
        $dto = $schema instanceof ScrapeSchemaDto ? $schema : ScrapeSchemaDto::fromArray($schema);
        $errors = [];

        foreach ($dto->fields as $fieldName => $field) {
            $this->validateField($fieldName, $field, $errors);
        }

        if ($errors !== []) {
            throw new ScrapeSchemaValidationException($errors);
        }

        return $dto;
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateField(string $path, FieldExtractionDto $field, array &$errors): void
    {
        if (! in_array($field->normalizedType(), FieldExtractionDto::SUPPORTED_TYPES, true)) {
            $errors[] = "{$path}.type must be one of: ".implode(', ', FieldExtractionDto::SUPPORTED_TYPES);
        }

        if ($field->prepend !== null && ! is_string($field->prepend)) {
            $errors[] = "{$path}.prepend must be a string or null";
        }

        if ($field->append !== null && ! is_string($field->append)) {
            $errors[] = "{$path}.append must be a string or null";
        }

        if ($field->usesValue()) {
            if (! is_string($field->value) || trim($field->value) === '') {
                $errors[] = "{$path}.value must be a non-empty string";
            }
        }

        $this->validateFieldValue($path, $field, $errors);

        if ($field->match !== null) {
            $this->validateMatch($path.'.match', $field->match, $errors);
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateFieldValue(string $path, FieldExtractionDto $field, array &$errors): void
    {
        $type = $field->normalizedType();
        $value = is_string($field->value) ? trim($field->value) : '';

        if ($type === 'schema_org') {
            return;
        }

        if ($value === '') {
            return;
        }

        if ($type === 'regex' && @preg_match($this->wrapRegex($field->value), '') === false) {
            $errors[] = "{$path}.value must be a valid regular expression";
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateMatch(string $path, MatchDefinitionDto $match, array &$errors): void
    {
        if ($match->default !== null && ! is_string($match->default)) {
            $errors[] = "{$path}.default must be a string or null";
        }

        foreach ($match->rules as $key => $rule) {
            $this->validateMatchRule("{$path}.{$key}", $rule, $errors);
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function validateMatchRule(string $path, MatchRuleDto $rule, array &$errors): void
    {
        if (! in_array($rule->type, ['match', 'regex'], true)) {
            $errors[] = "{$path}.type must be match or regex";
        }

        if (! is_string($rule->value) || trim($rule->value) === '') {
            $errors[] = "{$path}.value must be a non-empty string";
        }

        if ($rule->type === 'regex' && is_string($rule->value) && trim($rule->value) !== '' && @preg_match($this->wrapRegex($rule->value), '') === false) {
            $errors[] = "{$path}.value must be a valid regular expression";
        }
    }

    protected function wrapRegex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~i';
    }
}
