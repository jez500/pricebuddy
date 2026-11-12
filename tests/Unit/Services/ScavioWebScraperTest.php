<?php

namespace Tests\Unit\Services;

use App\Services\Scrapers\ScavioWebScraper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScavioWebScraperTest extends TestCase
{
    const AMAZON_URL = 'https://www.amazon.com/Lexar-PLAY-PRO-Express-Card/dp/B0DYB9TNB4/ref=sr_1_1?th=1';

    const WALMART_URL = 'https://www.walmart.com/ip/JLAB-STUDIO2-WIRELESS-HEADPHONE-BLACK/13544111159';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.scavio.key' => 'test-key',
        ]);
    }

    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/Scavio/'.$name.'.json')), true);
    }

    protected function scrape(string $url, bool $useCache = true): ScavioWebScraper
    {
        /** @var ScavioWebScraper $scraper */
        $scraper = (new ScavioWebScraper)->from($url)->setUseCache($useCache)->get();

        return $scraper;
    }

    public function test_request_for_amazon_urls(): void
    {
        $this->assertSame(
            ['endpoint' => '/api/v1/amazon/product', 'body' => ['asin' => 'B0DYB9TNB4', 'country' => 'us']],
            ScavioWebScraper::requestFor(self::AMAZON_URL)
        );
        $this->assertSame(
            ['asin' => 'B0CNT1RNZ8', 'country' => 'de'],
            ScavioWebScraper::requestFor('https://www.amazon.de/Western-Digital-24TB/dp/B0CNT1RNZ8?ref_=ast_sto_dp')['body']
        );
        $this->assertSame(
            ['asin' => 'B07Z75ZRSL', 'country' => 'gb'],
            ScavioWebScraper::requestFor('https://amazon.co.uk/gp/product/b07z75zrsl')['body']
        );
        $this->assertSame(
            ['asin' => 'B0DYB9TNB4', 'country' => 'jp'],
            ScavioWebScraper::requestFor('https://www.amazon.co.jp/gp/aw/d/B0DYB9TNB4/')['body']
        );
    }

    public function test_request_for_walmart_urls(): void
    {
        $this->assertSame(
            ['endpoint' => '/api/v1/walmart/product', 'body' => ['product_id' => '13544111159']],
            ScavioWebScraper::requestFor(self::WALMART_URL)
        );
        $this->assertSame(
            ['product_id' => '13544111159'],
            ScavioWebScraper::requestFor('https://walmart.com/ip/13544111159?athbdg=L1600')['body']
        );
    }

    public function test_request_for_unsupported_urls_is_null(): void
    {
        $this->assertNull(ScavioWebScraper::requestFor('https://www.amazon.com/s?k=sd+card'));
        $this->assertNull(ScavioWebScraper::requestFor('https://www.amazon.xyz/dp/B0DYB9TNB4'));
        $this->assertNull(ScavioWebScraper::requestFor('https://www.walmart.ca/en/ip/13544111159'));
        $this->assertNull(ScavioWebScraper::requestFor('https://www.walmart.com/search?q=headphones'));
        $this->assertNull(ScavioWebScraper::requestFor('https://example.com/dp/B0DYB9TNB4'));
    }

    public function test_get_posts_to_the_product_endpoint_with_bearer_key(): void
    {
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('amazon-product'))]);

        $scraper = $this->scrape(self::AMAZON_URL);

        $this->assertSame([], $scraper->getErrors());
        $this->assertSame('B0DYB9TNB4', $scraper->getJson('data.asin')->first());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.scavio.dev/api/v1/amazon/product'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->data() === ['asin' => 'B0DYB9TNB4', 'country' => 'us']);
    }

    public function test_schema_org_maps_amazon_product(): void
    {
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('amazon-product'))]);

        $schema = $this->scrape(self::AMAZON_URL)->getSchemaOrg()->first();

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Lexar 1TB PLAY PRO micro SD Express Card, 900MB/s Read, 4K, V30, UHS-I', $schema['name']);
        $this->assertSame(429.99, $schema['offers']['price']);
        $this->assertSame('USD', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame('https://m.media-amazon.com/images/I/61z50jYMXkL._AC_SL1500_.jpg', $schema['image']);
    }

    public function test_schema_org_maps_walmart_product(): void
    {
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('walmart-product'))]);

        $schema = $this->scrape(self::WALMART_URL)->getSchemaOrg()->first();

        $this->assertSame('JLab Studio 2 Wireless Headphones - Black; Lab Quality Sound', $schema['name']);
        $this->assertSame(31.87, $schema['offers']['price']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.scavio.dev/api/v1/walmart/product'
            && $request->data() === ['product_id' => '13544111159']);
    }

    public function test_walmart_out_of_stock_flag_maps_to_out_of_stock(): void
    {
        $response = $this->fixture('walmart-product');
        $response['data']['out_of_stock'] = true;
        Http::fake(['api.scavio.dev/*' => Http::response($response)]);

        $schema = $this->scrape(self::WALMART_URL)->getSchemaOrg()->first();

        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function test_amazon_without_price_maps_to_out_of_stock(): void
    {
        $response = $this->fixture('amazon-product');
        $response['data']['price'] = null;
        $response['data']['availability'] = 'Currently unavailable.';
        Http::fake(['api.scavio.dev/*' => Http::response($response)]);

        $schema = $this->scrape(self::AMAZON_URL)->getSchemaOrg()->first();

        $this->assertNull($schema['offers']['price']);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function test_successful_response_is_cached(): void
    {
        Http::fake(['api.scavio.dev/*' => Http::response($this->fixture('amazon-product'))]);

        $this->scrape(self::AMAZON_URL);
        // Same product, different tracking params: served from cache.
        $this->scrape('https://www.amazon.com/dp/B0DYB9TNB4?tag=abc-20');
        Http::assertSentCount(1);

        $this->scrape(self::AMAZON_URL, useCache: false);
        Http::assertSentCount(2);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function errorResponses(): array
    {
        return [
            'bad key' => [401, 'Scavio API rejected the key (401), check SCAVIO_API_KEY: Invalid API key'],
            'no credits' => [402, 'Scavio API credits are used up (402)'],
            'rate limited' => [429, 'Scavio API rate limit reached (429)'],
            'not found' => [404, 'Scavio API could not find this product (404)'],
            'upstream down' => [502, 'Scavio API error (502)'],
        ];
    }

    #[DataProvider('errorResponses')]
    public function test_error_responses_are_reported_and_not_cached(int $status, string $message): void
    {
        $error = $status === 401 ? 'Invalid API key' : 'Upstream message';
        Http::fake(['api.scavio.dev/*' => Http::response(['error' => $error], $status)]);

        $scraper = $this->scrape(self::AMAZON_URL);

        $this->assertSame('', $scraper->getBody());
        $this->assertSame($status, $scraper->getErrors()[0]['code']);
        $this->assertStringStartsWith($message, $scraper->getErrors()[0]['message']);
        $this->assertTrue($scraper->getSchemaOrg()->isEmpty());

        $this->scrape(self::AMAZON_URL);
        Http::assertSentCount(2);
    }

    public function test_timeout_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $scraper = $this->scrape(self::AMAZON_URL);

        $this->assertSame('', $scraper->getBody());
        $this->assertStringContainsString('Operation timed out', $scraper->getErrors()[0]['message']);
    }

    public function test_missing_key_makes_no_request(): void
    {
        config(['services.scavio.key' => null]);
        Http::fake();

        $scraper = $this->scrape(self::AMAZON_URL);

        $this->assertStringContainsString('SCAVIO_API_KEY is not set', $scraper->getErrors()[0]['message']);
        Http::assertNothingSent();
    }

    public function test_unsupported_url_makes_no_request(): void
    {
        Http::fake();

        $scraper = $this->scrape('https://example.com/product/1');

        $this->assertStringContainsString('does not support this URL', $scraper->getErrors()[0]['message']);
        Http::assertNothingSent();
    }

    public function test_request_timeout_comes_from_config(): void
    {
        config(['services.scavio.timeout' => 120]);

        $this->assertSame(120, (new ScavioWebScraper)->from(self::AMAZON_URL)->getRequestTimeout());
    }
}
