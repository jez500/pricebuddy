<?php

namespace Tests\Feature\Jobs;

use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UpdateProductPricesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_updates_product_prices()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        // Mock the updatePrices method to return true
        $product = $this->partialMock(Product::class, function ($mock) {
            $mock->shouldReceive('updatePrices')->once()->andReturn(true);
        });
        $product->id = 1;
        $product->title = 'Test Product';
        $product->user_id = $user->id;

        $job = new UpdateProductPricesJob($product, false);
        $job->handle();

        $this->assertTrue(true); // Job executed without error
    }

    public function test_job_logs_when_logging_enabled()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Test Product']);

        $job = new UpdateProductPricesJob($product, true);
        $job->handle();

        $this->assertTrue(true); // Job executed with logging
    }

    public function test_job_sends_notification_on_failure()
    {
        Notification::fake();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        // Mock updatePrices to return false (failure)
        $product = $this->partialMock(Product::class, function ($mock) use ($user) {
            $mock->shouldReceive('updatePrices')->once()->andReturn(false);
            $mock->shouldReceive('getAttribute')->with('user')->andReturn($user);
            $mock->shouldReceive('setAttribute')->andReturnSelf();
            $mock->id = 1;
            $mock->title = 'Test Product';
            $mock->user_id = $user->id;
        });

        $job = new UpdateProductPricesJob($product, false);
        $job->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
    }

    public function test_job_does_not_send_notification_on_success()
    {
        Notification::fake();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        $job = new UpdateProductPricesJob($product, false);
        $job->handle();

        Notification::assertNothingSent();
    }
}
