<?php

namespace Tests\Unit\Notifications;

use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_gotify_formats_message_correctly()
    {
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            99.99
        );

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertInstanceOf(GenericNotificationMessage::class, $gotifyMessage);
        $this->assertEquals('Price drop: Test Product ($99.99)', $gotifyMessage->title);
        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $gotifyMessage->content);
        $this->assertEquals(5, $gotifyMessage->priority); // Default priority set in the notification
        $this->assertEquals('https://example.com/product', $gotifyMessage->url);
    }

    public function test_to_apprise_formats_message_correctly()
    {
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            99.99
        );

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toApprise($user);

        $this->assertInstanceOf(GenericNotificationMessage::class, $gotifyMessage);
        $this->assertEquals('Price drop: Test Product ($99.99)', $gotifyMessage->title);
        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $gotifyMessage->content);
        $this->assertEquals('https://example.com/product', $gotifyMessage->url);
    }

    protected function createStoreProductAndPrice(string $storeName, string $productTitle, int $userId, float $price): array
    {
        $store = Store::factory()->create([
            'name' => $storeName,
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $product = Product::factory()->create(['title' => $productTitle, 'user_id' => $userId]);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        // Create associated price
        $url->prices()->create([
            'price' => $price,
            'store_id' => $store->id,
        ]);

        return [$store, $product, $url];
    }
}
