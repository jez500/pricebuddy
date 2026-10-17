<?php

namespace Tests\Feature\Services;

use App\Console\Commands\CreateStores;
use App\Enums\StockStatus;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TakealotStoreTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('price_buddy.scraper_api_url', 'http://scraper:3000');
        Http::preventStrayRequests();

        $this->artisan(CreateStores::COMMAND, ['country' => 'south-africa'])->assertExitCode(0);
        $this->store = Store::where('slug', 'takealot')->firstOrFail();
    }

    public function test_preset_scrapes_the_product_price_in_rand_using_the_browser_service(): void
    {
        $this->fakeBrowserResponse(file_get_contents(base_path('tests/Fixtures/Takealot/product.html')));

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertSame('Bosch Series 8 - 14 Place Freestanding Dishwasher A+++ Silver Inox', $result['title']);
        $this->assertSame('R 14,999', $result['price']);
        $this->assertSame(14999.0, CurrencyHelper::toFloat($result['price'], $this->store->locale, $this->store->currency));
        $this->assertSame('https://media.takealot.com/covers_images/b9d9c5bd248b4964b9a213b81519f934/s-pdpxl.file', $result['image']);
        $this->assertSame(StockStatus::InStock, ScrapeUrl::resolveStockStatus($result, $this->store->scrape_strategy->availability));
        $this->assertSame('en_ZA', $this->store->locale);
        $this->assertSame('ZAR', $this->store->currency);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://scraper:3000/api/article?')
            && $request['url'] === $this->store->test_url
            && $request['full-content'] === true
            && $request['wait-until'] === 'domcontentloaded'
            && $request['sleep'] === '5000'
        );
        Http::assertSentCount(1);
    }

    public function test_preset_matches_domains_with_and_without_www(): void
    {
        foreach (['takealot.com', 'www.takealot.com'] as $domain) {
            $store = ScrapeUrl::new('https://'.$domain.'/product/PLID93203543')->getStore();

            $this->assertNotNull($store);
            $this->assertTrue($this->store->is($store));
        }

        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('singleImageProducts')]
    public function test_single_image_products_use_the_main_photo_instead_of_thumbnails_or_recommendations(string $fixture, string $url, string $image): void
    {
        $this->fakeBrowserResponse(file_get_contents(base_path('tests/Fixtures/Takealot/'.$fixture)));

        $result = ScrapeUrl::new($url)->scrape();

        $this->assertSame($image, $result['image']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function singleImageProducts(): array
    {
        return [
            'Canon ink cartridges' => [
                'single-image-canon.html',
                'https://www.takealot.com/canon-ink-pg440xl-cl441xl-black-tri-colour-cartridge-oem/PLID44926016',
                'https://media.takealot.com/covers_tsins/47771220/47771220-1-pdpxl.jpg',
            ],
            'Seagate hard drive' => [
                'single-image-seagate.html',
                'https://www.takealot.com/seagate-ironwolf-pro-3-5-inch-12tb-internal-hard-drive/PLID93411769',
                'https://media.takealot.com/covers_images/0deb3195f5a14f5d9fd79cd78522c0d9/s-pdpxl.file',
            ],
        ];
    }

    public function test_out_of_stock_product_does_not_use_a_recommended_product_price(): void
    {
        $this->fakeBrowserResponse(<<<'HTML'
<html><body>
    <div data-ref="product-title"><h1>Unavailable dishwasher</h1></div>
    <span data-ref="stock-availability-status">Out of stock</span>
    <div class="product-card">
        <ul><li data-ref="price"><span class="currency">R 5,999</span></li></ul>
    </div>
</body></html>
HTML);

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertSame('Unavailable dishwasher', $result['title']);
        $this->assertNull($result['price']);
        $this->assertSame(StockStatus::OutOfStock, ScrapeUrl::resolveStockStatus($result, $this->store->scrape_strategy->availability));
    }

    public function test_loading_page_is_not_accepted_as_a_product(): void
    {
        $this->fakeBrowserResponse('<html><head><title>Takealot.com: Online Shopping</title></head><body><div id="shopfront-app">Loading</div></body></html>');

        $result = ScrapeUrl::new($this->store->test_url)
            ->setMaxAttempts(1)
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->scrape();

        $this->assertNull($result['title']);
        $this->assertNull($result['price']);
    }

    protected function fakeBrowserResponse(string $html): void
    {
        Http::fake([
            'http://scraper:3000/api/article*' => Http::response(['fullContent' => $html]),
        ]);
    }
}
