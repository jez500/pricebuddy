<?php

namespace App\Services;

use App\Actions\CreateStoreAction;
use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Closure;
use Exception;
use Illuminate\Support\Uri;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Symfony\Component\DomCrawler\Crawler;

class AutoCreateStore
{
    public const string DEFAULT_SCRAPER = ScraperService::Http->value;

    public const string ALT_SCRAPER = ScraperService::Api->value;

    protected WebScraperInterface $scraperService;

    protected array $strategies = [];

    public bool $logErrors = true;

    public function __construct(protected string $url, public ?string $html = null, string $scraper = self::DEFAULT_SCRAPER, int $timeout = 30)
    {
        $this->strategies = config('price_buddy.auto_create_store_strategies', []);

        if (empty($html)) {
            $this->scraperService = WebScraper::make($scraper)
                ->setConnectTimeout($timeout)
                ->setRequestTimeout($timeout)
                ->from($url)
                ->get();
            $this->html = $this->scraperService->getBody();
        } else {
            $this->scraperService = WebScraper::make($scraper)->setBody($this->html);
        }
    }

    public static function new(string $url, ?string $html = null, string $scraper = self::DEFAULT_SCRAPER, int $timeout = 30): self
    {
        return resolve(static::class, [
            'url' => $url,
            'html' => $html,
            'scraper' => $scraper,
            'timeout' => $timeout,
        ]);
    }

    public static function canAutoCreateFromUrl(string $url, int $timeout = 30): bool
    {
        return ! is_null(self::new($url, timeout: $timeout)->getStoreAttributes());
    }

    public static function createStoreFromUrl(string $url): ?Store
    {
        // Check if store exists.
        $host = strtolower(Uri::of($url)->host());

        if ($existing = Store::query()->domainFilter($host)->first()) {
            return $existing;
        }

        $attributes = self::new($url)->getStoreAttributes();

        return $attributes
            ? (new CreateStoreAction)($attributes)
            : null;
    }

    public function getStoreAttributes(): ?array
    {
        $detected = $this->detect();

        if ($detected === null) {
            $this->errorLog('Unable to auto create store', [
                'url' => $this->url,
                'html' => $this->html,
            ]);

            return null;
        }

        return self::buildAttributes($this->url, $detected['fields']);
    }

    /**
     * Detect a scrape strategy from the (already-fetched) HTML using the deterministic
     * heuristics (schema.org → selector → regex). Adds a schema.org availability field
     * when present. Returns the field strategies plus each extracted value, or null when
     * the required title+price could not be found.
     *
     * @return array{fields: array<string, array<string, string|null>>, extracted: array<string, mixed>}|null
     */
    public function detect(): ?array
    {
        $strategy = $this->strategyParse();
        $schemaOrg = ScraperStrategyType::SchemaOrg->value;

        if (
            (data_get($strategy, 'title.type') !== $schemaOrg && empty(data_get($strategy, 'title.value')))
            || (data_get($strategy, 'price.type') !== $schemaOrg && empty(data_get($strategy, 'price.value')))
        ) {
            return null;
        }

        $strategy['availability'] = $this->parseAvailability();

        $fields = [];
        $extracted = [];

        foreach ($strategy as $field => $parsed) {
            if (empty($parsed) || empty(data_get($parsed, 'type'))) {
                continue;
            }

            $fields[$field] = collect($parsed)->only('type', 'value')->all();
            $extracted[$field] = data_get($parsed, 'data');
        }

        return ['fields' => $fields, 'extracted' => $extracted];
    }

    /**
     * Assemble store attributes for a URL with a given scrape strategy. Shared by
     * heuristic auto-create and AI bootstrap so both produce identical store shapes.
     *
     * @param  array<string, mixed>  $scrapeStrategy
     * @return array<string, mixed>
     */
    public static function buildAttributes(string $url, array $scrapeStrategy): array
    {
        $host = strtolower(Uri::of($url)->host());

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return [
            'user_id' => auth()->id(),
            'domains' => [
                ['domain' => $host],
                ['domain' => 'www.'.$host],
            ],
            'name' => ucfirst($host),
            'scrape_strategy' => $scrapeStrategy,
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'scraper_service_settings' => '',
                'test_url' => $url,
                'locale_settings' => [
                    'locale' => CurrencyHelper::getLocale(),
                    'currency' => CurrencyHelper::getCurrency(),
                ],
            ],
        ];
    }

    public function strategyParse(): array
    {
        return [
            'title' => $this->parseTitle(),
            'price' => $this->parsePrice(),
            'image' => $this->parseImage(),
        ];
    }

    protected function parseTitle(): ?array
    {
        if ($match = $this->attemptSchemaOrg('title')) {
            return $match;
        }

        if ($match = $this->attemptSelectors($this->getStrategy('title', 'selector'))) {
            return $match;
        }

        if ($match = $this->attemptRegex($this->getStrategy('title', 'regex'))) {
            return $match;
        }

        return [];
    }

    protected function parsePrice(): ?array
    {
        $validateCallback = function ($value) {
            return CurrencyHelper::toFloat($value);
        };

        if ($match = $this->attemptSchemaOrg('price', $validateCallback)) {
            return $match;
        }

        if ($match = $this->attemptSelectors($this->getStrategy('price', 'selector'), $validateCallback)) {
            return $match;
        }

        if ($match = $this->attemptRegex($this->getStrategy('price', 'regex'), $validateCallback)) {
            return $match;
        }

        return [];
    }

    protected function parseAvailability(): array
    {
        return $this->attemptSchemaOrg('availability') ?? [];
    }

    protected function parseImage(): ?array
    {
        if ($match = $this->attemptSchemaOrg('image')) {
            return $match;
        }

        if ($match = $this->attemptSelectors($this->getStrategy('image', 'selector'))) {
            return $match;
        }

        if ($match = $this->attemptRegex($this->getStrategy('image', 'regex'))) {
            return $match;
        }

        return [];
    }

    protected function attemptSchemaOrg(string $field, ?Closure $validateValue = null): ?array
    {
        $extracted = SchemaOrgService::parseSchemaOrg($this->scraperService->getSchemaOrg(), $field)
            ?? SchemaOrgService::parseMicrodata($this->html, $field);

        $value = is_null($validateValue)
            ? $extracted
            : $validateValue($extracted);

        return ! empty($value)
            ? ['type' => ScraperStrategyType::SchemaOrg->value, 'value' => null, 'data' => $value]
            : null;
    }

    protected function attemptSelectors(array $selectors, ?Closure $validateValue = null): ?array
    {
        $value = null;
        $workingSelector = null;

        $dom = new Crawler($this->html);

        foreach ($selectors as $selector) {
            if ($value) {
                break;
            }

            $selectorSettings = ScrapeUrl::parseSelector($selector);
            $realSelector = $selectorSettings[0];
            $method = $selectorSettings[1] ?? 'text';
            $args = $selectorSettings[2] ?? [];

            try {
                $results = $dom->filter($realSelector)
                    ->each(function (Crawler $node) use ($method, $args, $validateValue) {
                        $extracted = call_user_func_array([$node, $method], $args);

                        return is_null($validateValue)
                            ? $extracted
                            : $validateValue($extracted);
                    });

                $value = data_get($results, '0');

                if ($value) {
                    $workingSelector = $selector;
                }
            } catch (DomSelectorException $e) {
                // not found.
            }
        }

        return ! empty($workingSelector)
            ? ['type' => 'selector', 'value' => $workingSelector, 'data' => $value]
            : null;
    }

    protected function attemptRegex(array $regexes, ?Closure $validateValue = null): ?array
    {
        $value = null;
        $workingRegex = null;

        foreach ($regexes as $regex) {
            if ($value) {
                break;
            }

            try {
                preg_match_all(ScrapeUrl::ensureRegexDelimiters($regex), $this->html, $matches);
                $extracted = data_get($matches, '1.0');

                $value = is_null($validateValue)
                    ? $extracted
                    : $validateValue($extracted);

                if ($value) {
                    $workingRegex = $regex;
                }
            } catch (Exception $e) {
            }
        }

        return ! empty($workingRegex)
            ? ['type' => 'regex', 'value' => $workingRegex, 'data' => $value]
            : null;
    }

    protected function getStrategy(string $fieldName, string $type): ?array
    {
        return data_get($this->strategies, $fieldName.'.'.$type);
    }

    protected function getStrategyValue(string $fieldName, string $type): ?string
    {
        return data_get($this->getStrategy($fieldName, $type), 'value');
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function setLogErrors(bool $logErrors): self
    {
        $this->logErrors = $logErrors;

        return $this;
    }

    protected function errorLog(string $message, array $data = []): void
    {
        if (! $this->logErrors) {
            return;
        }

        logger()->error($message, $data);
    }
}
