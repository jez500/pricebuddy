<?php

namespace Tests\Feature\Jobs;

use App\Enums\StockStatus;
use App\Jobs\RetryUrlPriceJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Notifications\ScrapeFailNotification;
use App\Notifications\StockAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class RetryUrlPriceCacheTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
    }

    /** @return array<string, array{?StockStatus, string, ?string, bool, int, bool}> */
    public static function retryResults(): array
    {
        return [
            'new price' => [null, '70', null, true, 1, false],
            'became unavailable with history' => [null, '', 'OutOfStock', true, 1, false],
            'became unavailable without history' => [null, '', 'OutOfStock', false, 1, false],
            'unchanged unavailable with history' => [StockStatus::OutOfStock, '', 'OutOfStock', true, 1, false],
            'unchanged unavailable without history' => [StockStatus::OutOfStock, '', 'OutOfStock', false, 1, false],
            'became available but missing price' => [StockStatus::OutOfStock, '', null, true, 1, true],
            'unchanged available missing price' => [null, '', null, true, 0, true],
            'unchanged unavailable unreadable price' => [StockStatus::OutOfStock, 'Call for price', 'OutOfStock', true, 0, true],
        ];
    }

    #[DataProvider('retryResults')]
    public function test_retry_rebuilds_insights_only_once_when_needed(?StockStatus $previous, string $price, ?string $availability, bool $history, int $expectedBuilds, bool $failed): void
    {
        [$product, $url] = $this->trackedUrl($previous, $history);
        $before = $product->only(['refresh_interval', 'next_check_at']);
        $this->mockScrape($price, 'Product', null, $availability);

        (new RetryUrlPriceJob($url, 2))->handle();

        // Count real calculations, even when saveQuietly sees an unchanged payload
        // and therefore emits no SQL UPDATE for a redundant calculation.
        $this->assertSame($expectedBuilds, RetryInsightsCountingProduct::$builds);
        if ($failed) {
            Queue::assertPushed(RetryUrlPriceJob::class, fn (RetryUrlPriceJob $job): bool => $job->attempt === 3);
        } else {
            Queue::assertNotPushed(RetryUrlPriceJob::class);
        }
        Notification::assertNotSentTo($product->user, ScrapeFailNotification::class);
        $expectedRows = ($history ? 1 : 0) + ($price === '70' ? 1 : 0);
        $this->assertSame($expectedRows, $url->prices()->count());
        $this->assertSame($availability === 'OutOfStock' ? StockStatus::OutOfStock : null, $url->fresh()->availability);
        $fresh = Product::query()->findOrFail($product->id);
        $this->assertEquals($fresh->buildPriceCache()->toArray(), $fresh->price_cache);
        $this->assertEquals($fresh->buildInsightsCache(), $fresh->insights_cache);
        $this->assertEquals($before, $fresh->only(array_keys($before)));
    }

    public function test_default_standalone_unchanged_unavailable_check_refreshes_both_caches(): void
    {
        [$product, $url] = $this->trackedUrl(StockStatus::OutOfStock, false);
        $product->forceFill(['price_cache' => [], 'insights_cache' => []])->saveQuietly();
        $this->mockScrape('', 'Product', null, 'OutOfStock');

        $this->assertTrue($url->updatePrice());

        $this->assertSame(1, RetryInsightsCountingProduct::$builds);
        $fresh = Product::query()->findOrFail($product->id);
        $this->assertNotEmpty($fresh->price_cache);
        $this->assertEquals($fresh->buildPriceCache()->toArray(), $fresh->price_cache);
        $this->assertEquals($fresh->buildInsightsCache(), $fresh->insights_cache);
        $this->assertSame(0, $url->prices()->count());
    }

    public function test_product_wide_refresh_keeps_one_insights_rebuild_for_all_urls(): void
    {
        [$product, $url] = $this->trackedUrl(StockStatus::OutOfStock, false);
        $other = Url::factory()->for($product, 'product')->create(['store_id' => $url->store_id, 'url' => 'https://example.com/retry-other']);
        // The already-loaded relations also let the counting subclass follow each
        // URL's real transaction/fresh-model path, without replacing the builder.
        $other->setRelation('product', $product);
        $product->setRelation('urls', $url->newCollection([$url, $other]));
        $this->mockScrape('', 'Product', null, 'OutOfStock');

        $this->assertTrue($product->updatePrices()->isEmpty());

        $this->assertSame(1, RetryInsightsCountingProduct::$builds);
        $fresh = Product::query()->findOrFail($product->id);
        $this->assertEquals($fresh->buildInsightsCache(), $fresh->insights_cache);
        $this->assertSame(0, $fresh->prices()->count());
    }

    public function test_retry_preserves_back_in_stock_and_price_notifications_without_duplicate_builds(): void
    {
        [$product, $url] = $this->trackedUrl(StockStatus::OutOfStock, true);
        $product->forceFill(['notify_in_stock' => true, 'notify_on_change' => true])->saveQuietly();
        $this->mockScrape('70', 'Product');

        (new RetryUrlPriceJob($url, 2))->handle();

        $this->assertSame(1, RetryInsightsCountingProduct::$builds);
        Notification::assertSentToTimes($product->user, StockAlertNotification::class, 1);
        Notification::assertSentToTimes($product->user, PriceAlertNotification::class, 1);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
        $this->assertSame(2, $url->prices()->count());
        $this->assertNull($url->fresh()->availability);
    }

    /** @return array{RetryInsightsCountingProduct, Url} Real persisted models, with only the calculator instrumented. */
    private function trackedUrl(?StockStatus $availability, bool $history): array
    {
        $product = Product::factory()->for(User::factory())->create([
            'notify_price' => null, 'notify_percent' => null, 'notify_in_stock' => false, 'notify_on_change' => false,
        ]);
        $store = Store::factory()->create();
        $store->update(['scrape_strategy' => array_merge($store->scrape_strategy->toArray(), [
            'availability' => [
                'type' => 'selector', 'value' => '.availability',
                'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'], 'default' => 'in_stock'],
            ],
        ])]);
        $factory = Url::factory()->for($product)->for($store);
        $url = ($history ? $factory->withPrices([60]) : $factory)
            ->create(['url' => 'https://example.com/retry-cache', 'availability' => $availability]);
        $product->updatePriceCache();
        $product->updateInsightsCache();
        $product->forceFill(['refresh_interval' => 3600, 'next_check_at' => now()->addHours(2)])->saveQuietly();
        $counted = RetryInsightsCountingProduct::query()->findOrFail($product->id);
        $url->setRelation('product', $counted);
        RetryInsightsCountingProduct::$builds = 0;

        return [$counted, $url];
    }
}

/** Delegates every calculation to production code, including on locked fresh models. */
class RetryInsightsCountingProduct extends Product
{
    protected $table = 'products';

    public static int $builds = 0;

    public function getForeignKey(): string
    {
        return 'product_id';
    }

    public function buildInsightsCache(): array
    {
        self::$builds++;

        return parent::buildInsightsCache();
    }
}
