<?php

namespace App\Services;

use App\Actions\CreateStoreAction;
use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Closure;
use Exception;
use Illuminate\Support\Uri;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Symfony\Component\DomCrawler\Crawler;

class AutoCreateStore
{
    public const DEFAULT_SCRAPER = ScraperService::Http->value;

    public const ALT_SCRAPER = ScraperService::Api->value;

    protected array $strategies = [];

    public function __construct(protected string $url, public ?string $html = null, string $scraper = self::DEFAULT_SCRAPER)
    {
        $this->strategies = config('price_buddy.auto_create_store_strategies', []);

        if (empty($html)) {
            $this->html = WebScraper::make($scraper)
                ->from($url)
                ->get()
                ->getBody();
        }
    }

    public static function new(string $url, ?string $html = null, string $scraper = self::DEFAULT_SCRAPER): self
    {
        return resolve(static::class, [
            'url' => $url,
            'html' => $html,
            'scraper' => $scraper,
        ]);
    }

    public static function canAutoCreateFromUrl(string $url): bool
    {
        return ! is_null(self::new($url)->getStoreAttributes());
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
        $strategy = $this->strategyParse();

        // Exit if required fields are missing.
        if (empty($strategy['title']['value']) || empty($strategy['price']['value'])) {
            logger()->error('Unable to auto create store', [
                'url' => $this->url,
                'strategy' => $strategy,
                'html' => $this->html,
            ]);

            return null;
        }

        $attributes = [
            'user_id' => auth()->id(),
        ];

        $host = strtolower(Uri::of($this->url)->host());

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $attributes['domains'] = [
            ['domain' => $host],
            ['domain' => 'www.'.$host],
        ];

        $attributes['name'] = ucfirst($host);

        $attributes['scrape_strategy'] = collect($this->strategyParse())
            ->mapWithKeys(function ($value, $key) {
                return [
                    $key => collect($value)->only('type', 'value')->all(),
                ];
            })
            ->toArray();

        $attributes['settings'] = [
            'scraper_service' => ScraperService::Http->value,
            'scraper_service_settings' => '',
            'test_url' => $this->url,
            'locale_settings' => [
                'locale' => CurrencyHelper::getLocale(),
                'currency' => CurrencyHelper::getCurrency(),
            ],
        ];

        return $attributes;
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

        if ($match = $this->attemptSelectors($this->getStrategy('price', 'selector'), $validateCallback)) {
            return $match;
        }

        if ($match = $this->attemptRegex($this->getStrategy('price', 'regex'), $validateCallback)) {
            return $match;
        }

        return [];
    }

    protected function parseImage(): ?array
    {
        if ($match = $this->attemptSelectors($this->getStrategy('image', 'selector'))) {
            return $match;
        }

        if ($match = $this->attemptRegex($this->getStrategy('image', 'regex'))) {
            return $match;
        }

        return [];
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
                preg_match_all($regex, $this->html, $matches);
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
}
