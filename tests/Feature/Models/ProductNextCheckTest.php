<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductNextCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_check_estimate_uses_custom_interval_when_set(): void
    {
        $product = Product::factory()->create(['refresh_interval' => 3600]);
        $product->forceFill(['next_check_at' => now()->addMinutes(30)])->saveQuietly();

        $this->assertTrue(
            $product->nextCheckEstimate()->equalTo($product->next_check_at)
        );
    }

    public function test_next_check_estimate_falls_back_to_global_cron(): void
    {
        SettingsHelper::setSetting('scrape_schedule', '0 6 * * *');
        $product = Product::factory()->create(['refresh_interval' => null]);

        $estimate = $product->nextCheckEstimate();

        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isFuture());
        $this->assertSame(6, $estimate->hour);
        $this->assertSame(0, $estimate->minute);
    }

    public function test_next_check_estimate_is_null_when_paused(): void
    {
        $product = Product::factory()->create(['refresh_interval' => 3600, 'paused' => true]);
        $product->forceFill(['next_check_at' => now()->addMinutes(30)])->saveQuietly();

        $this->assertNull($product->nextCheckEstimate());
    }

    public function test_current_check_period_start_for_custom_interval(): void
    {
        $product = Product::factory()->create(['refresh_interval' => 3600]);
        $next = now()->addMinutes(20);
        $product->forceFill(['next_check_at' => $next])->saveQuietly();

        $start = $product->currentCheckPeriodStart();

        // start = next_check_at - refresh_interval
        $this->assertSame(
            $next->copy()->subSeconds(3600)->toDateTimeString(),
            $start->toDateTimeString()
        );
    }

    public function test_current_check_period_start_is_null_when_paused(): void
    {
        $product = Product::factory()->create(['paused' => true]);

        $this->assertNull($product->currentCheckPeriodStart());
    }
}
