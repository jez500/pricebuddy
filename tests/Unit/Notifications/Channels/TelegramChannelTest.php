<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PriceAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Global settings (the shared bot token lives at the app level).
        NotificationsHelper::setSetting(NotificationMethods::Telegram, value: [
            'enabled' => true,
            'bot_token' => 'bot-token',
        ]);

        $this->user = User::factory()->withNotificationSettings([
            NotificationMethods::Telegram->value => [
                'enabled' => true,
                'chat_id' => '123456',
            ],
        ])->createOne();
    }

    public function test_get_settings_merges_app_token_and_user_chat_id()
    {
        $settings = TelegramChannel::getSettings($this->user);
        $this->assertTrue($settings['enabled']);
        $this->assertEquals('bot-token', $settings['bot_token']);
        $this->assertEquals('123456', $settings['chat_id']);
    }

    public function test_make_url()
    {
        $this->assertSame(
            'https://api.telegram.org/botbot-token/sendMessage',
            TelegramChannel::makeUrl('bot-token')
        );
    }

    public function test_send_notification()
    {
        $product = Product::factory()->addUrlsAndPrices()->create([
            'title' => 'Notif product',
        ]);

        $notification = new PriceAlertNotification($product->urls()->first());
        $message = $notification->toTelegram($this->user);

        $postUrl = 'api.telegram.org/botbot-token/sendMessage';

        Http::fake([
            $postUrl => Http::response(['ok' => true]),
        ]);

        (new TelegramChannel)->send($this->user, $notification);

        Http::assertSent(function (Request $request) use ($message, $postUrl) {
            return $request->url() === 'https://'.$postUrl &&
                $request->method() === 'POST' &&
                $request['chat_id'] === '123456' &&
                $request['parse_mode'] === 'HTML' &&
                str_contains($request['text'], $message->title) &&
                str_contains($request['text'], $message->content);
        });
    }

    public function test_no_request_without_chat_id()
    {
        $userWithoutChatId = User::factory()->withNotificationSettings([
            NotificationMethods::Telegram->value => ['enabled' => true],
        ])->createOne();

        $product = Product::factory()->addUrlsAndPrices()->create();
        $notification = new PriceAlertNotification($product->urls()->first());

        // Stray-request prevention means this fails if any request is made.
        (new TelegramChannel)->send($userWithoutChatId, $notification);

        Http::assertNothingSent();
    }

    public function test_disable_service()
    {
        $this->assertTrue(
            in_array(NotificationMethods::Telegram->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );

        $disabledUser = User::factory()->withNotificationSettings([
            NotificationMethods::Telegram->value => ['enabled' => false],
        ])->createOne();
        $this->assertFalse(
            in_array(NotificationMethods::Telegram->getChannel(), NotificationsHelper::getEnabledChannels($disabledUser)->all())
        );

        NotificationsHelper::setSetting(NotificationMethods::Telegram, value: [
            'enabled' => false,
            'bot_token' => 'bot-token',
        ]);
        $this->assertFalse(
            in_array(NotificationMethods::Telegram->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );
    }
}
