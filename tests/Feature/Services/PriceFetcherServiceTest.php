<?php

namespace Tests\Feature\Services;

use App\Enums\Statuses;
use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceFetcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PriceFetcherServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Product::query()->delete();
        $this->user = User::factory()->create();
    }

    /**
     * Collect every product id dispatched across all UpdateAllPricesJob jobs.
     *
     * @return array<int, int>
     */
    protected function dispatchedProductIds(): array
    {
        $ids = [];
        Bus::assertDispatched(UpdateAllPricesJob::class, function (UpdateAllPricesJob $job) use (&$ids) {
            $ids = array_merge($ids, $job->productIds);

            return true;
        });

        return $ids;
    }

    public function test_global_lane_skips_paused_and_custom_interval_products()
    {
        Bus::fake();

        $normal = Product::factory()->create(['user_id' => $this->user->id]);
        $paused = Product::factory()->create(['user_id' => $this->user->id, 'paused' => true]);
        $custom = Product::factory()->create(['user_id' => $this->user->id, 'refresh_interval' => 3600]);
        $archived = Product::factory()->create(['user_id' => $this->user->id, 'status' => Statuses::Archived]);

        PriceFetcherService::new()->updateAllPrices();

        $ids = $this->dispatchedProductIds();

        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($paused->id, $ids);
        $this->assertNotContains($custom->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_due_lane_only_dispatches_due_custom_interval_products()
    {
        Bus::fake();

        // Due: custom interval, next check in the past.
        $due = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $due->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        // Not due yet: next check in the future.
        $notDue = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $notDue->forceFill(['next_check_at' => now()->addHour()])->saveQuietly();

        // Paused custom-interval product, due but should be skipped.
        $paused = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
            'paused' => true,
        ]);
        $paused->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        // Global-schedule product (no interval) is not part of this lane.
        $global = Product::factory()->create(['user_id' => $this->user->id]);

        PriceFetcherService::new()->updateDuePrices();

        $ids = $this->dispatchedProductIds();

        $this->assertContains($due->id, $ids);
        $this->assertNotContains($notDue->id, $ids);
        $this->assertNotContains($paused->id, $ids);
        $this->assertNotContains($global->id, $ids);
    }

    public function test_due_lane_reschedules_next_check_into_the_future()
    {
        Bus::fake();

        $due = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $due->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        PriceFetcherService::new()->updateDuePrices();

        $next = $due->fresh()->next_check_at;
        $this->assertTrue($next->greaterThan(now()), 'next_check_at should be pushed into the future');
        // ~1 hour out (interval) plus up to 5 min jitter.
        $this->assertTrue($next->lessThanOrEqualTo(now()->addHour()->addMinutes(6)));
    }
}
