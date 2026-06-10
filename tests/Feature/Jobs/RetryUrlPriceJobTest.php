<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RetryUrlPriceJob;
use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RetryUrlPriceJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a partial-mocked Url whose product relation is a partial-mocked
     * Product, so we can drive updatePrice() outcomes without real scraping.
     *
     * @return array{0: Url, 1: Product}
     */
    private function makeUrlAndProduct(bool $paused = false): array
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->paused = $paused;
        $product->shouldReceive('updatePriceCache')->andReturnNull();

        $url = Mockery::mock(Url::class)->makePartial();
        $url->setRelation('product', $product);

        return [$url, $product];
    }

    public function test_reschedules_next_attempt_when_still_failing(): void
    {
        Queue::fake();
        Notification::fake();

        [$url] = $this->makeUrlAndProduct();
        $url->shouldReceive('updatePrice')->once()->andReturnNull();

        (new RetryUrlPriceJob($url, 2))->handle();

        Queue::assertPushed(
            RetryUrlPriceJob::class,
            fn (RetryUrlPriceJob $job) => $job->attempt === 3 && $job->url === $url && $job->delay !== null
        );
        Notification::assertNothingSent();
    }

    public function test_notifies_and_stops_when_attempts_exhausted(): void
    {
        Queue::fake();
        Notification::fake();

        $user = User::factory()->create();
        [$url, $product] = $this->makeUrlAndProduct();
        $product->setRelation('user', $user);
        $url->shouldReceive('updatePrice')->once()->andReturnNull();

        // Default max attempts is 3, so attempt 3 is the final one.
        (new RetryUrlPriceJob($url, 3))->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_stops_silently_on_success(): void
    {
        Queue::fake();
        Notification::fake();

        [$url] = $this->makeUrlAndProduct();
        $url->shouldReceive('updatePrice')->once()->andReturn(new Price);

        (new RetryUrlPriceJob($url, 2))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }

    public function test_exhaustion_notifies_once_per_product_across_urls(): void
    {
        Queue::fake();
        Notification::fake();

        $user = User::factory()->create();

        $product = Mockery::mock(Product::class)->makePartial();
        $product->id = 1;
        $product->paused = false;
        $product->shouldReceive('updatePriceCache')->andReturnNull();
        $product->setRelation('user', $user);

        $urlA = Mockery::mock(Url::class)->makePartial();
        $urlA->setRelation('product', $product);
        $urlA->shouldReceive('updatePrice')->once()->andReturnNull();

        $urlB = Mockery::mock(Url::class)->makePartial();
        $urlB->setRelation('product', $product);
        $urlB->shouldReceive('updatePrice')->once()->andReturnNull();

        // Both URLs of the same product exhaust their retries (attempt 3 of 3).
        (new RetryUrlPriceJob($urlA, 3))->handle();
        (new RetryUrlPriceJob($urlB, 3))->handle();

        // Only one product-level notification should be sent.
        Notification::assertSentToTimes($user, ScrapeFailNotification::class, 1);
    }

    public function test_aborts_when_product_is_paused(): void
    {
        Queue::fake();
        Notification::fake();

        [$url] = $this->makeUrlAndProduct(paused: true);
        $url->shouldReceive('updatePrice')->never();

        (new RetryUrlPriceJob($url, 2))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNothingSent();
    }
}
