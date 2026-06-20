<?php

namespace Tests\Feature\Insights;

use App\Events\NotifyPriceChangeEvent;
use App\Listeners\NotifyPriceChangeListener;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class NotifyPriceChangeTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Store::query()->delete();
        Store::factory()->create(['domains' => [['domain' => 'example.com']]]);
    }

    private function productWithPrices(): Product
    {
        return Product::factory()
            ->addUrlWithPrices('https://example.com/p1', [120, 110, 100, 95])
            ->create(['user_id' => $this->user->id, 'notify_price' => 100]);
    }

    public function test_changing_notify_price_dispatches_event(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'notify_price' => 100]);

        Event::fake([NotifyPriceChangeEvent::class]);

        $product->update(['notify_price' => 50]);

        Event::assertDispatched(NotifyPriceChangeEvent::class, fn (NotifyPriceChangeEvent $e): bool => $e->product->is($product));
    }

    public function test_unrelated_update_does_not_dispatch_event(): void
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'notify_price' => 100]);

        Event::fake([NotifyPriceChangeEvent::class]);

        $product->update(['title' => 'A new title']);

        Event::assertNotDispatched(NotifyPriceChangeEvent::class);
    }

    public function test_listener_rebuilds_insights_cache(): void
    {
        $product = $this->productWithPrices();
        $product->update(['insights_cache' => null]);

        (new NotifyPriceChangeListener)->handle(new NotifyPriceChangeEvent($product->fresh()));

        $this->assertIsArray($product->fresh()->insights_cache);
    }

    public function test_changing_notify_price_updates_target_tracker(): void
    {
        // QUEUE_CONNECTION=sync in phpunit.xml, so the queued listener runs inline.
        $product = $this->productWithPrices();

        $product->update(['notify_price' => 95]);

        $tracker = $product->fresh()->insights_cache['targetTracker'] ?? null;
        $this->assertNotNull($tracker);
        $this->assertSame(95.0, (float) $tracker['target']);
    }
}
