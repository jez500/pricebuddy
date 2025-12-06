<?php

namespace Tests\Feature\Jobs;

use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceFetcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAllPricesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Product::all()->each(fn ($product) => $product->delete());
    }

    public function test_job_calls_price_fetcher_service_with_product_ids()
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(3)->create(['user_id' => $user->id]);
        $productIds = $products->pluck('id')->toArray();

        $this->mock(PriceFetcherService::class, function ($mock) use ($productIds, $products) {
            $mock->shouldReceive('setLogging')
                ->once()
                ->with(true)
                ->andReturnSelf();

            $mock->shouldReceive('updatePrices')
                ->once()
                ->with($productIds)
                ->andReturn($products);
        });

        $job = new UpdateAllPricesJob($productIds);
        $job->handle();
    }

    public function test_job_can_be_instantiated_without_product_ids()
    {
        $job = new UpdateAllPricesJob;

        $this->assertInstanceOf(UpdateAllPricesJob::class, $job);
    }

    public function test_job_has_correct_timeout()
    {
        $job = new UpdateAllPricesJob([]);

        $this->assertSame(PriceFetcherService::JOB_TIMEOUT, $job->timeout);
    }
}
