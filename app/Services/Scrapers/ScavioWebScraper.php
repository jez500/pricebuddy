<?php

namespace App\Services\Scrapers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jez500\WebScraperForLaravel\AbstractWebScraper;

/**
 * Reads a product from the Scavio API (https://scavio.dev) instead of fetching the page.
 *
 * The product id is taken from the URL (Amazon ASIN, Walmart item id) and the API's JSON
 * response becomes the body, so JSON path strategies can read any field of it, eg
 * `data.list_price`. getSchemaOrg() also exposes the response as a schema.org Product, so
 * a store can use Schema.org strategies for title, price, image and availability.
 */
class ScavioWebScraper extends AbstractWebScraper
{
    public const string BASE_URL = 'https://api.scavio.dev';

    /**
     * Amazon marketplaces the API serves, keyed by the amazon.* domain suffix.
     */
    public const array AMAZON_COUNTRIES = [
        'com' => 'us',
        'ca' => 'ca',
        'com.mx' => 'mx',
        'com.br' => 'br',
        'co.uk' => 'gb',
        'de' => 'de',
        'fr' => 'fr',
        'it' => 'it',
        'es' => 'es',
        'nl' => 'nl',
        'com.be' => 'be',
        'se' => 'se',
        'pl' => 'pl',
        'com.tr' => 'tr',
        'ae' => 'ae',
        'sa' => 'sa',
        'eg' => 'eg',
        'in' => 'in',
        'co.jp' => 'jp',
        'cn' => 'cn',
        'sg' => 'sg',
        'com.au' => 'au',
    ];

    protected const string AMAZON_ASIN_PATTERN = '~/(?:dp|gp/product|gp/aw/d|o/ASIN|exec/obidos/ASIN)/([A-Z0-9]{10})(?:/|$)~i';

    protected const string WALMART_ITEM_PATTERN = '~^/ip/(?:[^/]+/)?(\d+)/?$~';

    public function __construct()
    {
        parent::__construct();

        // Product lookups take longer than a plain page fetch.
        $timeout = (int) config('services.scavio.timeout', 90);
        $this->scraperRequestTimeout = $timeout > 0 ? $timeout : 90;
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.scavio.key'));
    }

    /**
     * The API request for a product URL, or null when the URL is not a supported product page.
     *
     * @return array{endpoint: string, body: array<string, string>}|null
     */
    public static function requestFor(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_starts_with($host, 'amazon.')) {
            $country = self::AMAZON_COUNTRIES[substr($host, strlen('amazon.'))] ?? null;

            if ($country !== null && preg_match(self::AMAZON_ASIN_PATTERN, $path, $matches)) {
                return [
                    'endpoint' => '/api/v1/amazon/product',
                    'body' => ['asin' => strtoupper($matches[1]), 'country' => $country],
                ];
            }

            return null;
        }

        if ($host === 'walmart.com' && preg_match(self::WALMART_ITEM_PATTERN, $path, $matches)) {
            return [
                'endpoint' => '/api/v1/walmart/product',
                'body' => ['product_id' => $matches[1]],
            ];
        }

        return null;
    }

    public function getRequest(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) config('services.scavio.key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->getConnectTimeout())
            ->timeout($this->getRequestTimeout());
    }

    public function get(): self
    {
        $this->body = '';
        $request = self::requestFor((string) $this->url);

        if ($request === null) {
            $this->errors[] = [
                'message' => 'The Scavio API does not support this URL. Supported: Amazon and Walmart (walmart.com) product pages.',
                'code' => 0,
            ];

            return $this;
        }

        if (! self::isConfigured()) {
            $this->errors[] = [
                'message' => 'SCAVIO_API_KEY is not set. Add it to your .env file to use the Scavio API scraper.',
                'code' => 0,
            ];

            return $this;
        }

        // Keyed by the request rather than the URL so tracking params share one cached answer.
        $cacheKey = $this->getCacheKey($request['endpoint'].'?'.http_build_query($request['body']));

        if ($this->useCache && is_string($cached = Cache::get($cacheKey))) {
            $this->body = $cached;

            return $this;
        }

        try {
            $response = $this->getRequest()->post($request['endpoint'], $request['body']);
        } catch (ConnectionException $e) {
            logger()->error($e->getMessage());
            $this->errors[] = [
                'message' => 'Scavio API request failed: '.$e->getMessage(),
                'code' => $e->getCode(),
            ];

            return $this;
        }

        if (! $response->successful()) {
            $this->errors[] = [
                'message' => $this->errorMessage($response),
                'code' => $response->status(),
                'request' => $request,
            ];

            return $this;
        }

        $this->body = $response->body();

        // Only successful answers are cached, so a failed check is retried for real.
        if ($this->useCache) {
            Cache::put($cacheKey, $this->body, now()->addMinutes($this->cacheMinsTtl));
        }

        return $this;
    }

    /**
     * The API product as a schema.org Product, so Schema.org strategies work unchanged.
     *
     * Availability: Walmart reports `out_of_stock` directly. Amazon has no stock flag (its
     * `availability` is free text in the marketplace language), but it returns no price
     * when nobody is selling the item, so a missing price is read as out of stock.
     */
    public function getSchemaOrg(): Collection
    {
        $product = data_get(json_decode($this->body, true), 'data');

        if (! is_array($product) || blank($product['title'] ?? null)) {
            return collect();
        }

        $price = $product['price'] ?? null;
        $outOfStock = array_key_exists('out_of_stock', $product)
            ? (bool) $product['out_of_stock']
            : $price === null;

        return collect([[
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product['title'],
            'description' => $product['description'] ?? $product['short_description'] ?? null,
            'image' => data_get($product, 'images.0') ?? $product['image'] ?? null,
            'offers' => [
                '@type' => 'Offer',
                'price' => $price,
                'priceCurrency' => $product['currency'] ?? null,
                'availability' => $outOfStock ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
            ],
        ]]);
    }

    protected function errorMessage(Response $response): string
    {
        $status = $response->status();
        $detail = data_get($response->json(), 'error');
        $detail = is_string($detail) && $detail !== '' ? ': '.$detail : '';

        return match (true) {
            $status === 401 => 'Scavio API rejected the key (401), check SCAVIO_API_KEY'.$detail,
            $status === 402 => 'Scavio API credits are used up (402), top up at https://scavio.dev to resume checks'.$detail,
            $status === 429 => 'Scavio API rate limit reached (429), try again later'.$detail,
            $status === 404 => 'Scavio API could not find this product (404)'.$detail,
            default => 'Scavio API error ('.$status.')'.$detail,
        };
    }
}
