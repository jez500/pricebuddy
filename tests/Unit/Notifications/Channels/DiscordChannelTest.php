<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\PriceAlertNotification;
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
                $embed['color'] === DiscordChannel::COLOR_PRICE_DROP;
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
