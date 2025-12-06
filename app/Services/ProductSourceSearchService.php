<?php

namespace App\Services;

use App\Models\ProductSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Jez500\WebScraperForLaravel\Exceptions\DomSelectorException;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Psr\Log\LoggerInterface;

class ProductSourceSearchService
{
    protected LoggerInterface $logger;

    public bool $logErrors = true;

    protected array $keys = [
        'list_container',
        'product_title',
        'product_url',
    ];

    public function __construct(protected ProductSource $source)
    {
        // @phpstan-ignore-next-line - withContext is valid.
        $this->logger = Log::channel('db')->withContext(['source' => $source->getKey()]);
    }

    public static function new(ProductSource $source): self
    {
        return resolve(static::class, ['source' => $source]);
    }

    public function search(string $query): Collection
    {
        $searchUrl = $this->buildSearchUrl($query);
        $strategy = data_get($this->source, 'extraction_strategy', []);

        $scraper = WebScraper::make($this->source->scraper_service)
            ->from($searchUrl)
            ->get();

        if ($errors = $scraper->getErrors()) {
            $this->errorLog('Error scraping URL', [
                'store_id' => $this->source->getKey(),
                'errors' => $errors,
            ]);

            return collect();
        }

        $items = $this->scrapeOption($scraper, ($strategy['list_container']));

        try {
            // For each result, instantiate a new scraper and extract the title and url.
            return $items->map(function ($item) use ($strategy) {
                $itemScraper = WebScraper::http()->setBody($item);

                return [
                    'title' => $this->scrapeOption($itemScraper, $strategy['product_title'])->first(),
                    'url' => $this->scrapeOption($itemScraper, $strategy['product_url'])->first(),
                    'content' => $item,
                ];
            })
                ->reject(fn ($item) => empty($item['title']) || empty($item['url']))
                ->values();
        } catch (DomSelectorException $e) {
            $this->errorLog($e->getMessage());

            return collect();
        }
    }

    public function buildSearchUrl(string $query): string
    {
        return str_replace(':search_term', urlencode($query), $this->source->search_url);
    }

    protected function scrapeOption(WebScraperInterface $scraper, array $options, bool $multiple = false): Collection
    {
        $type = data_get($options, 'type');
        $value = data_get($options, 'value');

        $value = match ($type) {
            'selector' => ScrapeUrl::parseSelector($value),
            default => [$value]
        };

        $method = ScrapeUrl::getMethodFromType($type);

        try {
            // Return a collection of values.
            return call_user_func_array([$scraper, $method], $value);
        } catch (DomSelectorException $e) {
            $this->errorLog('Error scraping URL', [
                'error' => $e->getMessage(),
            ]);
        }

        return collect();
    }

    protected function errorLog(string $message, array $data = []): void
    {
        if (! $this->logErrors) {
            return;
        }

        $this->logger->error($message, $data);
    }
}
