<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

class ScrapeUrlTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const TEST_URL = 'https://example.com/product';

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('cache:clear');

        Store::query()->delete();

        $this->store = Store::factory()->createOne([
            'domains' => [['domain' => parse_url(self::TEST_URL, PHP_URL_HOST)]],
        ]);

        $this->user = User::factory()->create();
    }

    public function test_scrape_returns_correct_data()
    {
        $url = self::TEST_URL;
        $scrapeData = [
            'title' => 'Example Title',
            'price' => '100',
            'image' => 'https://example.com/image.png',
        ];

        $this->mockScrape($scrapeData['price'], $scrapeData['title'], $scrapeData['image']);

        $scrapeUrl = ScrapeUrl::new($url);
        $result = $scrapeUrl->scrape();

        $this->assertEquals($scrapeData['title'], $result['title']);
        $this->assertEquals($scrapeData['price'], $result['price']);
        $this->assertEquals($scrapeData['image'], $result['image']);
    }

    public function test_scrape_logs_error_on_missing_required_fields()
    {
        Log::shouldReceive('channel')->once()->andReturn(logger());
        Log::shouldReceive('withContext')->once()->andReturn(logger());
        Log::shouldReceive('error')->once();

        $this->mockScrape('invalid', 'invalid');

        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);

        $result = $scrapeUrl->scrape();

        $this->assertEmpty($result['title']);
        $this->assertEmpty($result['price']);
    }

    public function test_scrape_requires_store()
    {
        LogMessage::query()->delete();

        $scrapeUrl = ScrapeUrl::new('http://not-a-store.local');
        $result = $scrapeUrl->scrape();

        $this->assertEmpty($result);

        $this->assertSame(1, LogMessage::where('message', 'No store found for URL')->count());
    }

    public function test_scrape_retries_on_failure()
    {
        LogMessage::query()->delete();

        $this->mockScrape('invalid', 'invalid');

        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $result = $scrapeUrl->scrape();

        $this->assertEmpty($result['title']);
        $this->assertEmpty($result['price']);

        $this->assertSame(1, LogMessage::where('message', 'Error scraping URL 3 times')->count());
    }

    public function test_get_store_returns_correct_store()
    {
        $this->mockScrape(10, 'title');

        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $result = $scrapeUrl->getStore();

        $this->assertEquals($this->store->id, $result->id);
    }

    public function test_scrape_option_returns_correct_value()
    {
        $this->mockScrape('$10.00', 'Example Title');

        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $result = $scrapeUrl->scrape();

        $this->assertEquals('Example Title', $result['title']);
        $this->assertEquals('$10.00', $result['price']);
    }

    public static function regexDelimiterCases(): array
    {
        return [
            'bare alphanumeric start is wrapped' => ['https?://schema.org/(\w+)', '#https?://schema.org/(\w+)#'],
            'bare backslash start is wrapped' => ['\d+', '#\d+#'],
            'slash-delimited passes through' => ['/foo/', '/foo/'],
            'slash-delimited with flags passes through' => ['/foo/i', '/foo/i'],
            'hash-delimited passes through' => ['#https?://schema.org/(\w+)#', '#https?://schema.org/(\w+)#'],
            'tilde-delimited passes through' => ['~foo~', '~foo~'],
            'pattern containing # picks the next available delimiter' => ['fragment#section', '~fragment#section~'],
            'empty string is returned unchanged' => ['', ''],
            'dollar-anchored bare pattern is wrapped, not treated as delimited' => ['$([0-9.]+)', '#$([0-9.]+)#'],
            'single delimiter with no closing delimiter is wrapped' => ['/foo', '#/foo#'],
            'paired parens delimiter passes through' => ['(\d+)', '(\d+)'],
            'paired braces delimiter passes through' => ['{\d+}', '{\d+}'],
            'bare char class with quantifier is wrapped' => ['[0-9]+', '#[0-9]+#'],
        ];
    }

    /**
     * @dataProvider regexDelimiterCases
     */
    public function test_ensure_regex_delimiters(string $input, string $expected)
    {
        $this->assertSame($expected, ScrapeUrl::ensureRegexDelimiters($input));
    }

    public function test_scrape_regex_strategy_accepts_bare_pattern()
    {
        // Store strategies historically saved availability as a bare regex
        // (no delimiters), e.g. `https?://schema.org/(\w+)`. Without the fix,
        // preg_match_all warns "Delimiter must not be alphanumeric" and the
        // availability is silently null. This regression-tests that.
        $this->store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'],
                'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
                'availability' => ['type' => 'regex', 'value' => 'https?://schema.org/(\w+)'],
            ],
        ]);

        $this->mockScrape('$10.00', 'Example Title', 'https://example.com/x.png', 'http://schema.org/OutOfStock');

        $result = ScrapeUrl::new(self::TEST_URL)->scrape();

        $this->assertSame('OutOfStock', $result['availability']);
    }

    public function test_scrape_skips_strategy_entry_with_null_type()
    {
        // Real-world: a seeded store can have a strategy slot (e.g. availability)
        // with the match table populated but `type` left null. Before the guard,
        // this raised a TypeError in getMethodFromType and the whole scrape — for
        // every field, not just availability — bailed with a 500.
        $this->store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'],
                'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
                'availability' => [
                    'type' => null,
                    'value' => null,
                    'match' => ['default' => 'in_stock'],
                ],
            ],
        ]);

        $this->mockScrape('$10.00', 'Example Title', 'https://example.com/x.png');

        $result = ScrapeUrl::new(self::TEST_URL)->scrape();

        // Other fields scrape normally; availability is skipped (null).
        $this->assertSame('Example Title', $result['title']);
        $this->assertSame('$10.00', $result['price']);
        $this->assertNull($result['availability']);
    }

    public function test_scrape_regex_strategy_with_null_value_does_not_throw()
    {
        // A regex strategy slot can have its `type` set but `value` left null.
        // ensureRegexDelimiters() is typed `string`, so passing the null value
        // straight through the Regex match arm raised an uncaught TypeError and
        // failed the whole scrape. The arm now guards on is_string().
        $this->store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=og:price:amount]|content'],
                'image' => ['type' => 'selector', 'value' => 'meta[property=og:image]|content'],
                'availability' => ['type' => 'regex', 'value' => null],
            ],
        ]);

        $this->mockScrape('$10.00', 'Example Title', 'https://example.com/x.png');

        $result = ScrapeUrl::new(self::TEST_URL)->scrape();

        $this->assertSame('Example Title', $result['title']);
        $this->assertSame('$10.00', $result['price']);
        $this->assertNull($result['availability']);
    }

    public function test_scrape_schema_org()
    {
        $this->store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org', 'value' => null],
                'price' => ['type' => 'schema_org', 'value' => null],
                'image' => ['type' => 'schema_org', 'value' => null],
            ],
        ]);

        $this->mockScrapeSchema('49.99', 'Schema Title', 'https://example.com/schema.jpg');

        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $result = $scrapeUrl->scrape();

        $this->assertEquals('Schema Title', $result['title']);
        $this->assertEquals('49.99', $result['price']);
        $this->assertEquals('https://example.com/schema.jpg', $result['image']);
    }
}
