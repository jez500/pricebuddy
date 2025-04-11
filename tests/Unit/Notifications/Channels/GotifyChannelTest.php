<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\PriceAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GotifyChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Global settings.
        NotificationsHelper::setSetting(NotificationMethods::Gotify, value: [
            'enabled' => true,
            'url' => 'https://gotify.test',
            'token' => 'test-token',
        ]);

        $this->user = User::factory()->withNotificationSettings([
            NotificationMethods::Gotify->value => [
                'enabled' => true,
            ],
        ])->createOne();
    }

    public function test_get_settings_for_user()
    {
        $settings = GotifyChannel::getSettings($this->user);
        $this->assertTrue($settings['enabled']);
        $this->assertEquals('https://gotify.test', $settings['url']);
        $this->assertEquals('test-token', $settings['token']);
    }

    public function test_make_url()
    {
        $this->assertSame(
            'https://gotify.test/message?token=test-token',
            GotifyChannel::makeUrl('https://gotify.test', 'test-token')
        );
    }

    public function test_send_notification()
    {
        $product = Product::factory()->addUrlsAndPrices()->create([
            'title' => 'Notif product',
        ]);

        $notification = new PriceAlertNotification($product->urls()->first());
        $message = $notification->toApprise($this->user);

        $postUrl = 'gotify.test/message?token=test-token';

        Http::fake([
            $postUrl => Http::response(),
        ]);

        (new GotifyChannel)->send($this->user, $notification);

        Http::assertSent(function (Request $request) use ($message, $postUrl) {
            return $request->url() === 'https://'.$postUrl &&
                $request->method() === 'POST' &&
                $request['title'] === $message->title &&
                $request['message'] === $message->content &&
                $request['priority'] === $message->priority;
        });
    }

    public function test_disable_service()
    {
        $this->assertTrue(
            in_array(NotificationMethods::Gotify->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );

        $disabledUser = User::factory()->withNotificationSettings([
            NotificationMethods::Gotify->value => [
                'enabled' => false,
            ],
        ])->createOne();
        $this->assertFalse(
            in_array(NotificationMethods::Gotify->getChannel(), NotificationsHelper::getEnabledChannels($disabledUser)->all())
        );

        NotificationsHelper::setSetting(NotificationMethods::Gotify, value: [
            'enabled' => false,
            'url' => 'https://gotify.test',
            'token' => 'test-token',
        ]);
        $this->assertFalse(
            in_array(NotificationMethods::Gotify->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );
    }
}
