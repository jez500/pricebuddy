<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\PriceAlertNotification;
use App\Notifications\StockAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscordChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_user_webhook_overrides_default()
    {
        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => true,
            'webhook_url' => 'https://discord.com/api/webhooks/default',
        ]);

        // Falls back to the app-level default when the user has none.
        $user = User::factory()->withNotificationSettings([
            NotificationMethods::Discord->value => ['enabled' => true],
        ])->createOne();
        $this->assertEquals(
            'https://discord.com/api/webhooks/default',
            DiscordChannel::getSettings($user)['webhook_url']
        );

        // The user's own webhook takes precedence.
        $userWithOverride = User::factory()->withNotificationSettings([
            NotificationMethods::Discord->value => [
                'enabled' => true,
                'webhook_url' => 'https://discord.com/api/webhooks/mine',
            ],
        ])->createOne();
        $this->assertEquals(
            'https://discord.com/api/webhooks/mine',
            DiscordChannel::getSettings($userWithOverride)['webhook_url']
        );
    }

    public function test_send_notification_posts_embed()
    {
        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => true,
            'webhook_url' => 'https://discord.com/api/webhooks/default',
        ]);

        $user = User::factory()->withNotificationSettings([
            NotificationMethods::Discord->value => [
                'enabled' => true,
                'webhook_url' => 'https://discord.com/api/webhooks/mine',
            ],
        ])->createOne();

        $product = Product::factory()->addUrlsAndPrices()->create([
            'title' => 'Notif product',
            'image' => 'https://example.com/product.jpg',
        ]);

        $notification = new PriceAlertNotification($product->urls()->first());
        $message = $notification->toDiscord($user);

        Http::fake([
            'discord.com/api/webhooks/mine' => Http::response(),
        ]);

        (new DiscordChannel)->send($user, $notification);

        Http::assertSent(function (Request $request) use ($message) {
            $embed = $request['embeds'][0] ?? [];

            return $request->url() === 'https://discord.com/api/webhooks/mine' &&
                $request->method() === 'POST' &&
                $embed['title'] === $message->title &&
                $embed['description'] === $message->content &&
                $embed['url'] === $message->url &&
                $embed['color'] === DiscordChannel::COLOR_PRICE_DROP &&
                $embed['thumbnail']['url'] === 'https://example.com/product.jpg' &&
                in_array('Current price', array_column($embed['fields'], 'name')) &&
                $embed['footer']['text'] === 'PriceBuddy';
        });
    }

    public function test_simple_connection_test_remains_supported_and_limits_long_text(): void
    {
        Http::fake(['discord.com/*' => Http::response()]);

        DiscordChannel::sendRequest(
            'https://discord.com/api/webhooks/test',
            str_repeat('A', 300),
            str_repeat('B', 4500),
        );

        Http::assertSent(function (Request $request) {
            $embed = $request['embeds'][0];

            return strlen($embed['title']) === 256 &&
                strlen($embed['description']) === 4096 &&
                ! isset($embed['thumbnail']) &&
                ! isset($embed['fields']);
        });
    }

    public function test_stock_notification_posts_last_recorded_price_and_thumbnail(): void
    {
        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => true,
            'webhook_url' => 'https://discord.com/api/webhooks/default',
        ]);
        $user = User::factory()->create();
        $product = Product::factory()->addUrlsAndPrices(urlCount: 1, priceCount: 1)->create([
            'title' => 'Product back in stock',
            'image' => 'https://example.com/product.jpg',
        ]);
        Http::fake(['discord.com/*' => Http::response()]);

        (new DiscordChannel)->send($user, new StockAlertNotification($product->urls()->first()));

        Http::assertSent(function (Request $request) {
            $embed = $request['embeds'][0];
            $fields = array_column($embed['fields'], 'value', 'name');

            return $embed['title'] === 'Back in stock' &&
                $embed['description'] === 'Product back in stock' &&
                $embed['thumbnail']['url'] === 'https://example.com/product.jpg' &&
                isset($fields['Last recorded price'], $fields['Price recorded']) &&
                ! isset($fields['Current price']);
        });
    }

    public function test_no_request_without_webhook()
    {
        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => true,
        ]);

        $user = User::factory()->withNotificationSettings([
            NotificationMethods::Discord->value => ['enabled' => true],
        ])->createOne();

        $product = Product::factory()->addUrlsAndPrices()->create();
        $notification = new PriceAlertNotification($product->urls()->first());

        (new DiscordChannel)->send($user, $notification);

        Http::assertNothingSent();
    }

    public function test_disable_service()
    {
        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => true,
            'webhook_url' => 'https://discord.com/api/webhooks/default',
        ]);

        $user = User::factory()->withNotificationSettings([
            NotificationMethods::Discord->value => ['enabled' => true],
        ])->createOne();
        $this->assertTrue(
            in_array(NotificationMethods::Discord->getChannel(), NotificationsHelper::getEnabledChannels($user)->all())
        );

        NotificationsHelper::setSetting(NotificationMethods::Discord, value: [
            'enabled' => false,
            'webhook_url' => 'https://discord.com/api/webhooks/default',
        ]);
        $this->assertFalse(
            in_array(NotificationMethods::Discord->getChannel(), NotificationsHelper::getEnabledChannels($user)->all())
        );
    }
}
