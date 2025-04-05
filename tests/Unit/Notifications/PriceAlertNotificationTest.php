<?php

namespace Tests\Unit\Notifications;

use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GotifyMessage;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_gotify_formats_message_correctly()
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'name' => 'Test Store',
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);
        $product = Product::factory()->create(['title' => 'Test Product']);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        // Create associated price
        $url->prices()->create([
            'price' => 99.99,
            'store_id' => $store->id,
        ]);

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertInstanceOf(GotifyMessage::class, $gotifyMessage);
        $this->assertEquals('Price drop: Test Product ($99.99)', $gotifyMessage->title);
        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $gotifyMessage->content);
        $this->assertEquals(5, $gotifyMessage->priority); // Default priority set in the notification
        $this->assertEquals('https://example.com/product', $gotifyMessage->url);
    }

    public function test_to_gotify_formats_message_correctly_with_different_locale()
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'name' => 'Test Store DE',
            'settings' => [
                'locale_settings' => [
                    'locale' => 'de',
                    'currency' => 'EUR',
                ],
            ],
        ]);
        $product = Product::factory()->create(['title' => 'Test Produkt']);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.de/produkt',
        ]);

        // Create associated price
        $url->prices()->create([
            'price' => 123.45,
            'store_id' => $store->id,
        ]);

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertInstanceOf(GotifyMessage::class, $gotifyMessage);
        // Price formatting depends on Url model's logic which uses locale
        // Test key parts of title separately to avoid issues with locale-specific formatting
        // First part of the title should match exactly
        $this->assertStringStartsWith('Price drop: Test Produkt', $gotifyMessage->title);
        $this->assertStringStartsWith('Test Store DE has had a price drop for Test Produkt', $gotifyMessage->content);

        // For the price part, just verify it contains the numeric value and currency
        $this->assertMatchesRegularExpression('/123,45.*€/', $gotifyMessage->title);
        $this->assertMatchesRegularExpression('/123,45.*€/', $gotifyMessage->content);

        $this->assertEquals(5, $gotifyMessage->priority);
        $this->assertEquals('https://example.de/produkt', $gotifyMessage->url);
    }
}
