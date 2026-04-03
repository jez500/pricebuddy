<?php

namespace App\Services\Helpers;

use App\Enums\ScraperStrategyType;
use Jez500\WebScraperForLaravel\Dto\ScrapeSchemaDto;
use Jez500\WebScraperForLaravel\Exceptions\SchemaValidationException;

class ScrapeStrategyHelper
{
    /**
     * Build a ScrapeSchemaDto from a raw strategy array.
     *
     * Excludes schema_org fields (handled separately by SchemaOrgService)
     * and strips availability match config (incompatible with MatchDefinitionDto).
     */
    public static function toSchema(array $strategy): ScrapeSchemaDto
    {
        $fields = self::normalizeForExtraction(
            self::extractDtoCompatibleFields($strategy)
        );

        if ($fields === []) {
            return new ScrapeSchemaDto;
        }

        try {
            return ScrapeSchemaDto::fromArray($fields);
        } catch (SchemaValidationException) {
            return new ScrapeSchemaDto;
        }
    }

    /**
     * Extract the availability match config from the raw strategy.
     *
     * @return array<string, mixed>|null
     */
    public static function getAvailabilityMatch(array $strategy): ?array
    {
        $match = $strategy['availability']['match'] ?? null;

        return is_array($match) ? $match : null;
    }

    /**
     * Normalize field definitions for package consumption:
     * map 'selector' to 'css' and strip 'match' keys.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function normalizeForExtraction(array $fields): array
    {
        return array_map(function (array $field) {
            if (($field['type'] ?? '') === ScraperStrategyType::Selector->value) {
                $field['type'] = 'css';
            }

            unset($field['match']);

            return $field;
        }, $fields);
    }

    /**
     * Validate a raw strategy array by attempting DTO hydration.
     *
     * @return true|array<int, string>
     */
    public static function validate(array $strategy): true|array
    {
        $fields = self::normalizeForExtraction(
            self::extractDtoCompatibleFields($strategy)
        );

        if ($fields === []) {
            return true;
        }

        try {
            ScrapeSchemaDto::fromArray($fields);

            return true;
        } catch (SchemaValidationException $e) {
            return $e->errors();
        }
    }

    /**
     * Extract fields that are compatible with DTO hydration.
     *
     * Skips empty/non-array entries and schema_org fields (which bypass the DTO pipeline).
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function extractDtoCompatibleFields(array $strategy): array
    {
        $fields = [];

        foreach ($strategy as $key => $definition) {
            if (empty($definition) || ! is_array($definition)) {
                continue;
            }

            if (($definition['type'] ?? '') === ScraperStrategyType::SchemaOrg->value) {
                continue;
            }

            $fields[$key] = $definition;
        }

        return $fields;
    }
}
