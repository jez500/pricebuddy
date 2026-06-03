<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_setting_a_custom_interval_makes_the_product_due_now()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $this->assertNull($product->next_check_at);

        $product->update(['refresh_interval' => 3600]);

        $this->assertNotNull($product->fresh()->next_check_at);
        $this->assertTrue($product->fresh()->next_check_at->lessThanOrEqualTo(now()));
    }

    public function test_changing_the_interval_reschedules_the_next_check_now()
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        // Simulate a check already scheduled far in the future.
        $product->forceFill(['next_check_at' => now()->addDay()])->saveQuietly();

        $product->update(['refresh_interval' => 600]);

        $next = $product->fresh()->next_check_at;
        $this->assertNotNull($next);
        $this->assertTrue($next->lessThanOrEqualTo(now()));
    }

    public function test_clearing_the_interval_clears_next_check_at()
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);

        $this->assertNotNull($product->fresh()->next_check_at);

        $product->update(['refresh_interval' => null]);

        $this->assertNull($product->fresh()->next_check_at);
    }

    public function test_schedule_next_check_advances_by_interval()
    {
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $product->forceFill(['next_check_at' => now()->subHour()])->saveQuietly();

        $product->scheduleNextCheck();

        $next = $product->fresh()->next_check_at;
        $this->assertTrue($next->greaterThan(now()));
        $this->assertTrue($next->lessThanOrEqualTo(now()->addHour()->addMinutes(6)));
    }

    public function test_schedule_next_check_is_a_noop_without_an_interval()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $product->scheduleNextCheck();

        $this->assertNull($product->fresh()->next_check_at);
    }
}
