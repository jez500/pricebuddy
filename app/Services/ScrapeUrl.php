<?php

namespace App\Services;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperApi;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Psr\Log\LoggerInterface;

class ScrapeUrl
{
    public const string SELECTOR_ATTR_DELIMITER = '|';

    public const string SELECTOR_HTML_PREFIX = '!';

    /**
     * For the title and image, limit the length.
     */
    public const int MAX_STR_LENGTH = 1000;

    protected WebScraperInterface $webScraper;

    protected LoggerInterface $logger;

    protected int $scraperRequestTimeout = 30;

    protected int $scraperConnectTimeout = 30;

    protected string $scraperService = 'api';

    protected int $maxAttempts;

    protected array $keys = [
        'title',
        'description',
        'price',
        'image',
    ];

    public bool $sendUiNotifications = true;

    public bool $logErrors = true;

    public function __construct(protected string $url)
    {
        // @phpstan-ignore-next-line - withContext is valid.
        $this->logger = Log::channel('db')->withContext(['url' => $url]);
        $this->maxAttempts = SettingsHelper::getSetting('max_attempts_to_scrape', 3);
    }

    public static function new(string $url): self
    {
        return resolve(static::class, ['url' => $url]);
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function setLogErrors(bool $logErrors): self
    {
        $this->logErrors = $logErrors;

        return $this;
    }

    public function setSendUiNotifications(bool $sendUiNotifications): self
    {
        $this->sendUiNotifications = $sendUiNotifications;

        return $this;
    }

    public function setScraper(string $scraper): self
    {
        $this->scraperService = $scraper;
        $scraper = WebScraper::make($this->scraperService)
            ->setConnectTimeout($this->getConnectTimeout())
            ->setRequestTimeout($this->getRequestTimeout());

        if ($this->scraperService === ScraperService::Api->value) {
            /** @var WebScraperApi $scraper */
            $scraper->setScraperApiBaseUrl(
                config('price_buddy.scraper_api_url', 'http://scraper:3000')
            );
        }

        $this->webScraper = $scraper;

        return $this;
    }

    public function scrape(array $options = []): array
    {
        $attempt = 0;
        $output = [];

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            // Don't use cache if previous attempt failed.
            if ($attempt > 1) {
                $options['use_cache'] = false;
            }

            $output = $this->scrapeUrl($options);

            if ($output === false) {
                $attempt = $this->maxAttempts;
                $output = [];
            }

            if (! empty($output['title'])) {
                break;
            }
        }

        foreach (['price', 'title'] as $required) {
            if (empty($output[$required])) {
                $this->errorLog('Error scraping URL '.$attempt.' times', [
                    'attempts' => $attempt,
                    'error' => __('Missing :field when scraping', ['field' => $required]),
                    'scrape_errors' => $output['errors'] ?? [],
                    'scraped_html' => $output['body'] ?? '',
                ]);
                $this->errorNotification('Missing required field: '.$required);

                return $output;
            }
        }

        return $output;
    }

    protected function scrapeUrl(array $options = []): array|false
    {
        $store = data_get($options, 'store') ?? $this->getStore();
        $useCache = data_get($options, 'use_cache', true);

        if (! $store) {
            $this->errorLog('No store found for URL');
            $this->errorNotification('No store found for URL');

            return false;
        }

        $output = [
            'store' => $store,
        ];

        try {
            $this->setScraper($store->scraper_service);

            $scraper = $this->webScraper->from($this->url)
                ->setCacheMinsTtl(AppSettings::new()->scrape_cache_ttl)
                ->setUseCache($useCache)
                ->setOptions($store->scraper_options);

            $page = $scraper->get();

            if ($errors = $scraper->getErrors()) {
                $this->errorLog('Error scraping URL', [
                    'store_id' => $store->getKey(),
                    'errors' => $errors,
                ]);
                $this->errorNotification('Error scraping URL check logs');

                return $output;
            }

            $strategy = data_get($store, 'scrape_strategy', []);

            foreach ($this->keys as $key) {
                if (empty($strategy[$key]) || ! is_array($strategy[$key])) {
                    $output[$key] = null;
                } else {
                    $output[$key] = $this->scrapeOption($page, $strategy[$key]);
                }
            }

            $output['body'] = $page->getBody();
            $output['errors'] = $scraper->getErrors();
        } catch (Exception $e) {
            $this->errorLog('Error scraping URL', [
                'error' => $e->getMessage(),
            ]);
        }

        return $output;
    }

    public function getStore(): ?Store
    {
        $host = Uri::of($this->url)->host();

        return Store::query()->domainFilter($host)->oldest()->first();
    }

    protected function scrapeOption(WebScraperInterface $scraper, array $options): ?string
    {
        $type = data_get($options, 'type');
        $value = data_get($options, 'value');

        $method = self::getMethodFromType($type);

        $value = match ($type) {
            'selector' => self::parseSelector($value),
            default => [$value]
        };

        try {
            return implode('', [
                data_get($options, 'prepend', ''),
                call_user_func_array([$scraper, $method], $value)?->first(),
                data_get($options, 'append', ''),
            ]);
        } catch (DomSelectorException $e) {
            $this->errorLog('Error scraping URL', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->errorNotification($e->getMessage());
        }

        return null;
    }

    public static function getMethodFromType(string $type): string
    {
        return match ($type) {
            'regex' => 'getRegex',
            'json' => 'getJson',
            'xpath' => 'getXpath',
            default => 'getSelector'
        };
    }

    public static function parseSelector(string $selector): array
    {
        // If starts with exclamation !, return unsanitized HTML.
        if (str_starts_with($selector, self::SELECTOR_HTML_PREFIX)) {
            $selector = substr($selector, 1) ?: '';

            return [$selector, 'html'];
        }

        // If contains a pipe | extract attribute.
        if (! str_contains($selector, self::SELECTOR_ATTR_DELIMITER)) {
            return [$selector, 'text'];
        }

        // We get the attribute value from the selector assuming format is
        // .selector|attribute
        $parts = explode(self::SELECTOR_ATTR_DELIMITER, $selector);
        $attr = array_pop($parts);

        return [implode(self::SELECTOR_ATTR_DELIMITER, $parts), 'attr', [$attr]];
    }

    protected function errorNotification(string $message): void
    {
        if (! $this->sendUiNotifications) {
            return;
        }

        Notification::make()
            ->title('Scrape error')
            ->body($message)
            ->danger()
            ->send();
    }

    protected function errorLog(string $message, array $data = []): void
    {
        if (! $this->logErrors) {
            return;
        }

        $this->logger->error($message, $data);
    }

    public function getRequestTimeout(): int
    {
        return $this->scraperRequestTimeout;
    }

    public function setRequestTimeout(int $scraperRequestTimeout): self
    {
        $this->scraperRequestTimeout = $scraperRequestTimeout;

        return $this;
    }

    public function getConnectTimeout(): int
    {
        return $this->scraperConnectTimeout;
    }

    public function setConnectTimeout(int $scraperConnectTimeout): self
    {
        $this->scraperConnectTimeout = $scraperConnectTimeout;

        return $this;
    }

    /**
     * If a scraped field is greater than the max length, return null. This protects the db
     * against incorrect and long strings for url or image, both can't be cropped.
     */
    public static function preSaveMaxLength(?string $value): ?string
    {
        return $value && strlen($value) < self::MAX_STR_LENGTH ? $value : null;
    }

    /**
     * For fields that can be truncated, truncate them, eg title attribute. Like preSaveMaxLength,
     * protect the db from long strings.
     */
    public static function preSaveTruncate(?string $value): ?string
    {
        return Str::limit($value, self::MAX_STR_LENGTH);
    }
}
