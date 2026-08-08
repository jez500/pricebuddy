<?php

namespace Tests\Unit\Services;

use App\Enums\StockStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_soft_404_pages_drop_banner_prices_and_mark_discontinued()
    {
        $this->store->update([
            'settings' => ['scraper_service' => 'http'],
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'title'],
                'price' => ['type' => 'regex', 'value' => '\$([0-9]+(?:\.[0-9]{2})?)'],
                'image' => ['type' => 'selector', 'value' => 'img|src'],
            ],
        ]);

        // Soft 404: real title/body markers, but a shipping-threshold dollar amount
        // that a naive price regex would happily scrape.
        Http::fake([
            '*' => Http::response(<<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>404 Not Found</title>
    <link rel="canonical" href="https://example.com/404">
</head>
<body class="template-404">
    <p>Orders Over $899!</p>
    <p>Sorry, the page you were looking for does not exist.</p>
</body>
</html>
HTML),
        ]);

        $result = ScrapeUrl::new(self::TEST_URL)->scrape();

        $this->assertNull($result['price'] ?? null);
        $this->assertSame('https://schema.org/Discontinued', $result['availability'] ?? null);
    }

    public function test_looks_like_not_found_page_detects_common_markers()
    {
        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $method = new \ReflectionMethod(ScrapeUrl::class, 'looksLikeNotFoundPage');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($scrapeUrl, '404 Not Found', ''));
        $this->assertTrue($method->invoke($scrapeUrl, 'Page not found', ''));
        $this->assertTrue($method->invoke($scrapeUrl, 'Widget', '<body class="template-404"></body>'));
        $this->assertTrue($method->invoke($scrapeUrl, 'Widget', '<html data-page-type="404"></html>'));
        $this->assertTrue($method->invoke(
            $scrapeUrl,
            'Widget',
            '<link rel="canonical" href="https://example.com/404">'
        ));
        $this->assertTrue($method->invoke(
            $scrapeUrl,
            'Widget',
            '<link href="https://example.com/404" rel="canonical">'
        ));
        $this->assertFalse($method->invoke($scrapeUrl, 'Pellet Grill', '<body><p>$899 product</p></body>'));
    }

    /**
     * "404" is a real model number on plenty of products, so it must not condemn a page
     * on its own — only alongside error wording, or as the entire title.
     */
    public function test_looks_like_not_found_page_ignores_404_used_as_a_model_number()
    {
        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $method = new \ReflectionMethod(ScrapeUrl::class, 'looksLikeNotFoundPage');

        foreach (['Roland SP-404', 'Roland SP-404MKII Sampler', 'Peugeot 404 Wing Mirror', 'AR-404 Amplifier'] as $title) {
            $this->assertFalse(
                $method->invoke($scrapeUrl, $title, '<body><p>$899</p></body>'),
                $title.' should not be treated as a 404 page'
            );
        }

        foreach (['404', '404 Error', 'Error 404 | Store', '404 - Page Not Found'] as $title) {
            $this->assertTrue(
                $method->invoke($scrapeUrl, $title, ''),
                $title.' should be treated as a 404 page'
            );
        }
    }

    /**
     * The 404 body markers must be anchored to the attribute they belong to, so a theme's
     * bundled CSS or JSON mentioning a 404 template cannot condemn a real product page.
     */
    public function test_looks_like_not_found_page_ignores_incidental_404_mentions_in_body()
    {
        $scrapeUrl = ScrapeUrl::new(self::TEST_URL);
        $method = new \ReflectionMethod(ScrapeUrl::class, 'looksLikeNotFoundPage');

        $this->assertFalse($method->invoke(
            $scrapeUrl,
            'Pellet Grill',
            '<style>.template-404 .banner { display: none; }</style><body class="template-product"><p>$899</p></body>'
        ));

        $this->assertFalse($method->invoke(
            $scrapeUrl,
            'Pellet Grill',
            '<a href="https://example.com/404">Report a broken link</a>'
        ));
    }

    /**
     * The store's availability match config must not be able to re-interpret a soft 404
     * back into "in stock" — the page simply has no product on it.
     */
    public function test_soft_404_resolves_to_discontinued_despite_a_store_availability_match_config()
    {
        $this->store->update([
            'scrape_strategy' => [
                'title' => ['type' => 'selector', 'value' => 'title'],
                'availability' => [
                    'type' => 'selector',
                    'value' => '.stock',
                    'match' => [
                        'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                        'default' => 'in_stock',
                    ],
                ],
            ],
        ]);

        $strategy = $this->store->fresh()->scrape_strategy->availability;

        $this->assertSame(
            StockStatus::InStock,
            ScrapeUrl::resolveStockStatus(['availability' => 'https://schema.org/Discontinued'], $strategy),
            'sanity check: the raw availability value alone is overridden by the match config default'
        );

        $this->assertSame(
            StockStatus::Discontinued,
            ScrapeUrl::resolveStockStatus([
                'availability' => 'https://schema.org/Discontinued',
                ScrapeUrl::NOT_FOUND_KEY => true,
            ], $strategy)
        );
    }
}
