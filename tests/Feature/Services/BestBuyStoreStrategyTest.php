<?php

namespace Tests\Feature\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BestBuyStoreStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
        Store::query()->delete();
        Http::preventStrayRequests();

        $this->artisan('buddy:create-stores', ['country' => 'usa'])->assertExitCode(0);
    }

    public function test_new_item_uses_visible_price_instead_of_embedded_open_box_offers(): void
    {
        $store = Store::where('slug', 'bestbuy')->firstOrFail();
        $this->assertSame([
            ['domain' => 'bestbuy.com'],
            ['domain' => 'www.bestbuy.com'],
        ], $store->domains);

        $url = 'https://www.bestbuy.com/site/laptop/1234567.p';
        // Synthetic reproduction based on the issue thread, not a captured retailer response.
        $html = <<<'HTML'
            <html><head><meta property="og:title" content="Everyday Laptop"></head><body>
            <script type="application/json">{"sku":"1234567","offers":[{"price":812.99}],"primaryImage":{"__typename":"ProductImage","piscesHref":"https://pisces.bbystatic.com/image2/laptop.jpg"}}</script>
            <span>$999.00</span>
            <a href="/site/laptop/1234567.p/openbox">Open-Box: from $829.99</a>
            </body></html>
            HTML;
        Http::fake([$url => Http::response($html)]);

        $result = ScrapeUrl::new($url)->scrape(['use_cache' => false]);

        $this->assertTrue($result['store']->is($store));
        $this->assertSame('999.00', $result['price']);
        $this->assertSame('Everyday Laptop', $result['title']);
        $this->assertSame('https://pisces.bbystatic.com/image2/laptop.jpg', $result['image']);
    }

    public function test_bare_domain_and_open_box_url_extract_their_own_product_prices(): void
    {
        $url = 'https://bestbuy.com/site/monitor/7654321.p';
        $openBoxUrl = $url.'/openbox';
        Http::fake([
            $url => Http::response(<<<'HTML'
                <html><head><meta property="og:title" content="Desktop Monitor"></head><body>
                <script type="application/json">{"sku":"7654321","offers":[{"price":199.99}],"primaryImage":{"__typename":"ProductImage","piscesHref":"https://pisces.bbystatic.com/image2/monitor.jpg"}}</script>
                <span>$249.99</span><a href="/openbox">Open-Box: from $209.99</a>
                </body></html>
                HTML),
            $openBoxUrl => Http::response('<meta property="og:title" content="Open Box Monitor"><span>$812.99</span>'),
        ]);

        $result = ScrapeUrl::new($url)->scrape(['use_cache' => false]);

        $this->assertSame('bestbuy', $result['store']->slug);
        $this->assertSame('249.99', $result['price']);
        $this->assertSame('Desktop Monitor', $result['title']);
        $this->assertSame('https://pisces.bbystatic.com/image2/monitor.jpg', $result['image']);

        $openBox = ScrapeUrl::new($openBoxUrl)->scrape(['use_cache' => false]);

        $this->assertSame('812.99', $openBox['price']);
        $this->assertSame('Open Box Monitor', $openBox['title']);
        Http::assertSent(fn ($request) => $request->url() === $openBoxUrl);
    }

    public function test_visible_price_with_thousands_separator_is_extracted(): void
    {
        $url = 'https://www.bestbuy.com/site/tv/2345678.p';
        Http::fake([$url => Http::response(
            '<meta property="og:title" content="Big TV"><script type="application/json">{"offers":[{"price":949.99}]}</script><span>$1,099.00</span><span>$49.99</span>'
        )]);

        $result = ScrapeUrl::new($url)->scrape(['use_cache' => false]);

        $this->assertSame('1,099.00', $result['price']);
    }

    public function test_embedded_numeric_offer_without_visible_price_returns_null(): void
    {
        $url = 'https://www.bestbuy.com/site/laptop/1234567.p';
        Http::fake([$url => Http::response(
            '<meta property="og:title" content="Everyday Laptop"><script type="application/json">{"offers":[{"price":812.99}]}</script>'
        )]);

        $result = ScrapeUrl::new($url)->scrape(['use_cache' => false]);

        $this->assertSame('Everyday Laptop', $result['title']);
        $this->assertNull($result['price']);
    }

    public function test_defaults_preserve_custom_strategy_until_explicit_update(): void
    {
        $store = Store::where('slug', 'bestbuy')->firstOrFail();
        $strategy = $store->scrape_strategy->toArray();
        $strategy['price']['value'] = '~"price":(\d+(\.\d{2})?)~';
        $store->update(['scrape_strategy' => $strategy]);

        $this->artisan('buddy:create-stores', ['country' => 'usa'])->assertExitCode(0);
        $this->assertSame($strategy['price']['value'], $store->refresh()->scrape_strategy->price->value);

        $this->artisan('buddy:create-stores', ['country' => 'usa', '--update' => true])->assertExitCode(0);
        $this->assertSame('~>\$((?:\d{1,3}(?:,\d{3})+|\d+)(\.\d{2})?)<~', $store->refresh()->scrape_strategy->price->value);

        $url = 'https://www.bestbuy.com/site/laptop/1234567.p';
        Http::fake([$url => Http::response(
            '<meta property="og:title" content="Everyday Laptop"><script type="application/json">{"price":812.99}</script><span>$999.00</span>'
        )]);

        $result = ScrapeUrl::new($url)->scrape(['use_cache' => false]);

        $this->assertSame('999.00', $result['price']);
    }
}
