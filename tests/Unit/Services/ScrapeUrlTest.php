<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Models\User;
use App\Services\ScrapeUrl;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

class ScrapeUrlTest extends TestCase
{
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
}
