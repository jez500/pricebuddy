<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Notifications\StockAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_gotify_formats_message_correctly()
    {
        [$user, $url] = $this->createStoreProductAndUrl('Test Store', 'Test Product');

        $message = (new StockAlertNotification($url))->toGotify($user);

        $this->assertInstanceOf(GenericNotificationMessage::class, $message);
        $this->assertEquals('Back in stock: Test Product', $message->title);
        $this->assertStringContainsString('Test Store has Test Product back in stock', $message->content);
        $this->assertEquals(5, $message->priority);
    }

    public function test_telegram_discord_ntfy_share_generic_message()
    {
        [$user, $url] = $this->createStoreProductAndUrl('Test Store', 'Test Product');

        $notification = new StockAlertNotification($url);

        foreach (['toTelegram', 'toDiscord', 'toNtfy', 'toApprise'] as $method) {
            $message = $notification->{$method}($user);
            $this->assertInstanceOf(GenericNotificationMessage::class, $message);
            $this->assertEquals('Back in stock: Test Product', $message->title);
        }
    }

    public function test_database_array_contains_product_details()
    {
        [$user, $url] = $this->createStoreProductAndUrl('Test Store', 'Test Product');

        $data = (new StockAlertNotification($url))->toArray($user);

        $this->assertEquals('Back in stock: Test Product', $data['title']);
        $this->assertEquals('Test Product', $data['productName']);
        $this->assertEquals('Test Store', $data['storeName']);
    }

    /**
     * @return array{0: User, 1: Url}
     */
    protected function createStoreProductAndUrl(string $storeName, string $productTitle): array
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'name' => $storeName,
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $product = Product::factory()->create(['title' => $productTitle, 'user_id' => $user->getKey()]);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        return [$user, $url];
    }
}
