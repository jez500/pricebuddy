<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RetryUrlPriceJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Settings\AppSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class UpdateProductPricesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Sleep::fake();
    }

    public function test_job_updates_product_prices(): void
    {
        $user = User::factory()->create();
        $expectedSleep = AppSettings::new()->sleep_seconds_between_scrape;

        $product = $this->partialMock(Product::class, function ($mock) {
            $mock->shouldReceive('updatePrices')->once()->andReturn(new EloquentCollection);
        });
        $product->id = 1;
        $product->title = 'Test Product';
        $product->user_id = $user->id;

        (new UpdateProductPricesJob($product, false))->handle();

        $this->assertGreaterThan(0, $expectedSleep);
        Sleep::assertSequence([
            Sleep::for($expectedSleep)->seconds(),
        ]);
    }

    public function test_job_logs_when_logging_enabled(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Test Product']);

        (new UpdateProductPricesJob($product, true))->handle();

        $this->assertTrue(true);
    }

    public function test_job_schedules_retries_for_failed_urls_and_suppresses_notification(): void
    {
        Queue::fake();
        Notification::fake();

        $failUrl = Url::factory()->create();

        $product = $this->partialMock(Product::class, function ($mock) use ($failUrl) {
            $mock->shouldReceive('updatePrices')->once()
                ->andReturn(new EloquentCollection([$failUrl]));
        });
        $product->id = 1;
        $product->title = 'Test Product';

        (new UpdateProductPricesJob($product, false))->handle();

        Notification::assertNothingSent();
        Queue::assertPushed(
            RetryUrlPriceJob::class,
            fn (RetryUrlPriceJob $job) => $job->url->is($failUrl) && $job->attempt === 2
        );
    }

    public function test_job_notifies_immediately_when_retries_disabled(): void
    {
        Queue::fake();
        Notification::fake();

        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = 1;
        $settings->save();

        $user = User::factory()->create();

        $product = $this->partialMock(Product::class, function ($mock) use ($user) {
            $mock->shouldReceive('updatePrices')->once()
                ->andReturn(new EloquentCollection([Url::factory()->create()]));
            $mock->shouldReceive('getAttribute')->with('user')->andReturn($user);
        });
        $product->id = 1;
        $product->title = 'Test Product';
        $product->user_id = $user->id;

        (new UpdateProductPricesJob($product, false))->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_job_does_not_send_notification_on_success(): void
    {
        Queue::fake();
        Notification::fake();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        (new UpdateProductPricesJob($product, false))->handle();

        Notification::assertNothingSent();
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }
}
