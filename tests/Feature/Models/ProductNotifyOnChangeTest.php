<?php

namespace Tests\Feature\Models;

use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductNotifyOnChangeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Url $url;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::factory()->create();
    }

    protected function createUrl(array $productAttributes = []): Url
    {
        $store = Store::factory()->create([
            'name' => 'Rival Store',
            'settings' => ['locale_settings' => ['locale' => 'en_US', 'currency' => 'USD']],
        ]);

        $product = Product::factory()->create(array_merge([
            'title' => 'Widget',
            'user_id' => $this->user->id,
            'notify_price' => null,
            'notify_percent' => null,
            'notify_on_change' => true,
        ], $productAttributes));

        return Url::factory()->for($store)->for($product)->create();
    }

    protected function addPrice(Url $url, float $price): Price
    {
        // Scrapes happen at different times. The latest price is read by created_at.
        $this->travel(1)->hours();

        return $url->prices()->create(['price' => $price, 'store_id' => $url->store_id]);
    }

    protected function assertAlertSent(string $title, string $summary): void
    {
        Notification::assertSentTo(
            $this->user,
            PriceAlertNotification::class,
            function (PriceAlertNotification $notification) use ($title, $summary) {
                $data = $notification->toArray($this->user);

                return $data['title'] === $title && $data['summary'] === $summary;
            }
        );
    }

    public function test_price_increase_sends_an_increase_alert(): void
    {
        $url = $this->createUrl();
        $this->addPrice($url, 100);
        Notification::assertNothingSent();

        $price = $this->addPrice($url, 120);

        $this->assertAlertSent(
            'Price increase: Widget ($120.00)',
            'Rival Store has had a price increase for Widget - $120.00 (was $100.00)'
        );
        $this->assertTrue($price->fresh()->notified);
    }

    /**
     * The direction comes from the price that triggered the alert, not from a newer
     * price saved before the notification is rendered.
     */
    public function test_change_type_uses_the_triggering_price(): void
    {
        $url = $this->createUrl(['notify_on_change' => false]);
        $this->addPrice($url, 100);
        $increase = $this->addPrice($url, 120);
        $this->addPrice($url, 80);

        $notification = new PriceAlertNotification($url->fresh(), 100.0, $increase);

        $this->assertStringStartsWith('Price increase:', $notification->toArray($this->user)['title']);
    }

    public function test_price_decrease_sends_a_drop_alert(): void
    {
        $url = $this->createUrl();
        $this->addPrice($url, 100);
        $this->addPrice($url, 80);

        $this->assertAlertSent(
            'Price drop: Widget ($80.00)',
            'Rival Store has had a price drop for Widget - $80.00 (was $100.00)'
        );
    }

    public function test_unchanged_price_sends_nothing(): void
    {
        $url = $this->createUrl();
        $this->addPrice($url, 100);
        $this->addPrice($url, 100);

        Notification::assertNothingSent();
    }

    public function test_price_change_sends_nothing_when_the_option_is_off(): void
    {
        $url = $this->createUrl(['notify_on_change' => false]);
        $this->addPrice($url, 100);
        $this->addPrice($url, 120);

        Notification::assertNothingSent();
    }

    public function test_threshold_alert_still_works_when_the_option_is_off(): void
    {
        $url = $this->createUrl(['notify_on_change' => false, 'notify_price' => 90]);
        $this->addPrice($url, 100);
        $this->addPrice($url, 80);

        $this->assertAlertSent('Price drop: Widget ($80.00)', 'Rival Store has had a price drop for Widget - $80.00');
    }
}
