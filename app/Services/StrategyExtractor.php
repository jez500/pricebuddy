<?php

namespace App\Services;

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
     *
     * @param  array<string, mixed>  $slot
     */
    public static function extract(WebScraperInterface $scraper, array $slot, string $field): ?string
    {
        $type = data_get($slot, 'type');
        $value = data_get($slot, 'value');

        if (! is_string($type) || $type === '') {
            return null;
        }

        if (! is_string($value) && $type !== ScraperStrategyType::SchemaOrg->value) {
            return null;
        }

        if ($type === ScraperStrategyType::SchemaOrg->value) {
            return SchemaOrgService::parseSchemaOrg($scraper->getSchemaOrg(), $field);
        }

        $method = ScrapeUrl::getMethodFromType($type);

        $args = match ($type) {
            ScraperStrategyType::Selector->value => ScrapeUrl::parseSelector($value),
            ScraperStrategyType::Regex->value => [ScrapeUrl::ensureRegexDelimiters($value)],
            default => [$value],
        };

        $extracted = call_user_func_array([$scraper, $method], $args)?->first();

        if ($extracted === null || $extracted === '') {
            return null;
        }

        return implode('', [
            data_get($slot, 'prepend', ''),
            $extracted,
            data_get($slot, 'append', ''),
        ]);
    }
}
