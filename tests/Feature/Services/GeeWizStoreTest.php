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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeeWizStoreTest extends TestCase
{
    use RefreshDatabase;

    protected const IMAGE = 'https://www.geewiz.co.za/32038-large_default/seagate-10tb-ironwolf-7200-rpm-sata-iii-35-internal-nas-network-attached-storage-hard-disk-drive-hdd.jpg';

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('price_buddy.scraper_api_url', 'http://scraper:3000');
        Http::preventStrayRequests();

        $this->artisan(CreateStores::COMMAND, ['country' => 'south-africa'])->assertExitCode(0);
        $this->store = Store::where('slug', 'geewiz')->firstOrFail();
    }

    public function test_preset_scrapes_the_product_in_rand_using_the_browser_service(): void
    {
        $this->fakeBrowserResponse($this->fixture());

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertSame('Seagate 10TB IronWolf 7200 rpm SATA III 3.5" Internal NAS (Network Attached Storage) Hard Disk Drive (HDD)', $result['title']);
        $this->assertSame('8890.00', $result['price']);
        $this->assertSame(8890.0, CurrencyHelper::toFloat($result['price'], $this->store->locale, $this->store->currency));
        $this->assertSame(self::IMAGE, $result['image']);
        $this->assertSame(StockStatus::InStock, ScrapeUrl::resolveStockStatus($result, $this->store->scrape_strategy->availability));
        $this->assertSame('en_ZA', $this->store->locale);
        $this->assertSame('ZAR', $this->store->currency);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://scraper:3000/api/article?')
            && $request['url'] === $this->store->test_url
            && $request['full-content'] === true
            && $request['wait-until'] === 'domcontentloaded'
            && $request['sleep'] === '1000'
        );
        Http::assertSentCount(1);
    }

    public function test_preset_matches_domains_with_and_without_www(): void
    {
        foreach (['geewiz.co.za', 'www.geewiz.co.za'] as $domain) {
            $store = ScrapeUrl::new('https://'.$domain.'/some-category/27746-some-product.html')->getStore();

            $this->assertNotNull($store);
            $this->assertTrue($this->store->is($store));
        }

        Http::assertNothingSent();
    }

    public function test_price_and_image_are_not_taken_from_related_products_or_the_excl_vat_amount(): void
    {
        $this->fakeBrowserResponse($this->fixture());

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertSame('8890.00', $result['price']);
        $this->assertSame(self::IMAGE, $result['image']);
    }

    /**
     * The variants edit the Product JSON-LD of the observed in-stock page. Only the in-stock
     * layout was seen live; the other availability states are not real GeeWiz captures.
     */
    #[DataProvider('availabilityVariants')]
    public function test_availability_is_resolved_from_the_product_schema(string $availability, StockStatus $expected): void
    {
        $this->fakeBrowserResponse($this->fixtureWithProduct(
            function (array $product) use ($availability): array {
                $product['offers']['availability'] = $availability;

                return $product;
            }
        ));

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertSame($expected, ScrapeUrl::resolveStockStatus($result, $this->store->scrape_strategy->availability));
    }

    /**
     * @return array<string, array{string, StockStatus}>
     */
    public static function availabilityVariants(): array
    {
        return [
            'in stock' => ['https://schema.org/InStock', StockStatus::InStock],
            'out of stock' => ['https://schema.org/OutOfStock', StockStatus::OutOfStock],
            'pre order' => ['https://schema.org/PreOrder', StockStatus::PreOrder],
            'back order' => ['https://schema.org/BackOrder', StockStatus::BackOrder],
        ];
    }

    public function test_single_image_listing_uses_that_image(): void
    {
        $this->fakeBrowserResponse($this->fixtureWithProduct(
            function (array $product): array {
                $product['image'] = self::IMAGE;

                return $product;
            }
        ));

        $this->assertSame(self::IMAGE, ScrapeUrl::new($this->store->test_url)->scrape()['image']);
    }

    public function test_unavailable_product_without_an_offer_price_does_not_use_a_recommended_price(): void
    {
        $this->fakeBrowserResponse($this->fixtureWithProduct(
            function (array $product): array {
                $product['offers'] = ['availability' => 'https://schema.org/OutOfStock'];

                return $product;
            }
        ));

        $result = ScrapeUrl::new($this->store->test_url)->scrape();

        $this->assertNotEmpty($result['title']);
        $this->assertNull($result['price']);
        $this->assertSame(StockStatus::OutOfStock, ScrapeUrl::resolveStockStatus($result, $this->store->scrape_strategy->availability));
    }

    public function test_cloudflare_block_page_is_not_accepted_as_a_product(): void
    {
        $this->fakeBrowserResponse('<html><head><title>Attention Required! | Cloudflare</title></head><body>Sorry, you have been blocked</body></html>');

        $result = ScrapeUrl::new($this->store->test_url)
            ->setMaxAttempts(1)
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->scrape();

        $this->assertNull($result['title']);
        $this->assertNull($result['price']);
    }

    protected function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/GeeWiz/product.html'));
    }

    /**
     * Rewrite the fixture's Product JSON-LD with the given callback.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    protected function fixtureWithProduct(callable $mutate): string
    {
        $pattern = '#(<script type="application/ld\+json" id="adpmicrodatos-product[^>]*>)(.*?)(</script>)#s';

        $html = preg_replace_callback($pattern, function (array $matches) use ($mutate): string {
            $product = json_decode($matches[2], true, flags: JSON_THROW_ON_ERROR);

            return $matches[1].json_encode($mutate($product), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).$matches[3];
        }, $this->fixture(), 1, $count);

        $this->assertSame(1, $count, 'Fixture is missing the Product JSON-LD.');

        return $html;
    }

    protected function fakeBrowserResponse(string $html): void
    {
        Http::fake([
            'http://scraper:3000/api/article*' => Http::response(['fullContent' => $html]),
        ]);
    }
}
