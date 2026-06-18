<?php

namespace App\Services;

use App\Dto\StandardStrategyDto;
use App\Enums\ScraperStrategyType;
use Jez500\WebScraperForLaravel\WebScraperInterface;

class StrategyExtractor
{
    /**
     * Apply a single scrape-strategy slot to an already-loaded scraper and
     * return the extracted string (with prepend/append), or null when the core
     * extraction yields nothing (null or empty string).
     *
     * May throw Jez500\WebScraperForLaravel\Exceptions\DomSelectorException on an
     * invalid selector/xpath; callers decide whether to swallow or surface it.
     */
    public static function extract(WebScraperInterface $scraper, StandardStrategyDto $slot, string $field): ?string
    {
        $type = $slot->type;

        if ($type === ScraperStrategyType::SchemaOrg) {
            return SchemaOrgService::parseSchemaOrg($scraper->getSchemaOrg(), $field)
                ?? SchemaOrgService::parseMicrodata($scraper->getBody(), $field);
        }

        $value = $slot->value;

        if ($value === null) {
            return null;
        }

        $method = ScrapeUrl::getMethodFromType($type->value);

        $args = match ($type) {
            ScraperStrategyType::Selector => ScrapeUrl::parseSelector($value),
            ScraperStrategyType::Regex => [ScrapeUrl::ensureRegexDelimiters($value)],
            default => [$value],
        };

        $extracted = call_user_func_array([$scraper, $method], $args)?->first();

        if ($extracted === null || $extracted === '') {
            return null;
        }

        return implode('', [
            $slot->prepend ?? '',
            $extracted,
            $slot->append ?? '',
        ]);
    }
}
