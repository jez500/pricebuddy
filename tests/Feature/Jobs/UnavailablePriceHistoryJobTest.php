<?php

namespace Tests\Feature\Jobs;

use App\Enums\StockStatus;
use App\Jobs\RetryUrlPriceJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceHistoryCleanup;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class UnavailablePriceHistoryJobTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Sleep::fake();
    }

    /** @return array<string, array{bool, int}> */
    public static function scheduledChecks(): array
    {
        return [
            'cleared history, retries disabled' => [true, 1],
            'cleared history, retries enabled' => [true, 3],
            'no initial history, retries disabled' => [false, 1],
            'no initial history, retries enabled' => [false, 3],
        ];
    }

    #[DataProvider('scheduledChecks')]
    public function test_scheduled_unavailable_scrape_with_empty_history_is_successful(bool $clearHistory, int $maxAttempts): void
    {
        [$user, $product, $url] = $this->emptyHistory($clearHistory);
        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = $maxAttempts;
        $settings->save();
        $this->mockScrape('', 'Unavailable product', null, 'OutOfStock');

        (new UpdateProductPricesJob($product->fresh(), false))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNotSentTo($user, ScrapeFailNotification::class);
        $this->assertSame(0, $url->prices()->count());
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        $this->assertEquals(0, $product->fresh()->current_price);
        $this->assertDatabaseHas('urls', ['id' => $url->id, 'product_id' => $product->id]);
    }

    /** @return array<string, array{bool, int}> */
    public static function retryChecks(): array
    {
        return [
            'cleared history, intermediate attempt' => [true, 2],
            'cleared history, exhausted attempt' => [true, 3],
            'no initial history, intermediate attempt' => [false, 2],
            'no initial history, exhausted attempt' => [false, 3],
        ];
    }

    #[DataProvider('retryChecks')]
    public function test_unavailable_retry_with_empty_history_stops_without_failure(bool $clearHistory, int $attempt): void
    {
        [$user, $product, $url] = $this->emptyHistory($clearHistory);
        $this->mockScrape('', 'Unavailable product', null, 'OutOfStock');

        (new RetryUrlPriceJob($url->fresh(), $attempt))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNotSentTo($user, ScrapeFailNotification::class);
        $this->assertSame(0, $url->prices()->count());
        $this->assertEquals(0, $product->fresh()->current_price);
    }

    /** @return array<string, array{string, ?string}> */
    public static function failedScrapes(): array
    {
        return [
            'missing in-stock price' => ['', null],
            'unreadable unavailable price' => ['Call for price', 'OutOfStock'],
        ];
    }

    #[DataProvider('failedScrapes')]
    public function test_real_failed_scrapes_still_notify_for_scheduled_and_exhausted_retry_jobs(string $price, ?string $availability): void
    {
        [$user, $product, $url] = $this->emptyHistory(false);
        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = 1;
        $settings->save();
        $this->mockScrape($price, 'Product', null, $availability);

        $this->assertNull($url->updatePrice());
        (new UpdateProductPricesJob($product->fresh(), false))->handle();
        Notification::assertSentToTimes($user, ScrapeFailNotification::class, 1);

        Notification::fake();
        (new RetryUrlPriceJob($url->fresh(), 3))->handle();
        Notification::assertSentToTimes($user, ScrapeFailNotification::class, 1);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
        $this->assertSame(0, $url->prices()->count());
    }

    public function test_missing_price_policy_requires_a_real_product_title_to_succeed_without_history(): void
    {
        [, , $url] = $this->emptyHistory(false);
        $url->store->update(['settings' => array_merge($url->store->settings, ['missing_price_out_of_stock' => true])]);
        $this->mockScrape('', 'Unavailable product');

        $this->assertTrue($url->updatePrice());
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        $this->assertSame(0, $url->prices()->count());

        // Supplying a separate result avoids reusing the first page's scrape cache.
        $this->assertNull($url->updatePrice(null, ['price' => '', 'title' => '', 'availability' => null, 'store' => $url->store]));
        $this->assertSame(0, $url->prices()->count());
    }

    public function test_mixed_product_check_only_reports_real_failures_and_unavailable_url_can_record_a_future_price(): void
    {
        [, $product, $unavailable] = $this->emptyHistory(true);
        $failed = Url::factory()->for($product)->create(['url' => 'https://example.com/failed', 'store_id' => $unavailable->store_id]);
        $available = Url::factory()->for($product)->create(['url' => 'https://example.com/available', 'store_id' => $unavailable->store_id]);
        $metadata = '<meta property="og:title" content="Product">';
        Http::fake([
            $unavailable->url => Http::response($metadata.'<span class="availability">OutOfStock</span>'),
            $failed->url => Http::response($metadata),
            $available->url => Http::response($metadata.'<meta property="og:price:amount" content="10">'),
        ]);

        $failedUrls = $product->fresh()->updatePrices();

        $this->assertSame([$failed->id], $failedUrls->modelKeys());
        $this->assertSame(0, $unavailable->prices()->count());
        $this->assertSame(0, $failed->prices()->count());
        $this->assertSame(1, $available->prices()->count());
        $record = $unavailable->fresh()->updatePrice(95, ['price' => 95, 'availability' => null]);
        $this->assertInstanceOf(Price::class, $record);
        $this->assertEquals(95, $record->price);
        $this->assertSame(1, $unavailable->prices()->count());
        $this->assertNull($unavailable->fresh()->availability);
    }

    /** @return array{User, Product, Url} A tracked unavailable URL with genuinely empty raw history. */
    private function emptyHistory(bool $clearHistory): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['notify_price' => null, 'notify_percent' => null]);
        $store = Store::factory()->create();
        $store->update([
            'scrape_strategy' => array_merge($store->scrape_strategy->toArray(), [
                'availability' => [
                    'type' => 'selector', 'value' => '.availability',
                    'match' => ['out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'], 'default' => 'in_stock'],
                ],
            ]),
        ]);
        $factory = Url::factory()->for($product)->for($store);
        $url = ($clearHistory ? $factory->withPrices([60, 70]) : $factory)
            ->create(['url' => 'https://example.com/unavailable-item', 'availability' => StockStatus::OutOfStock]);

        if ($clearHistory) {
            $cleanup = app(PriceHistoryCleanup::class);
            $snapshot = $cleanup->snapshot($user, $product, $url->prices()->pluck('id')->all());
            $this->assertSame(2, $cleanup->deleteSnapshot($user, $product, $snapshot));
        }

        return [$user, $product, $url];
    }
}
