<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\AppriseChannel;
use App\Notifications\PriceAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppriseChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_get_settings_for_user()
    {
        // Global settings.
        NotificationsHelper::setSetting(NotificationMethods::Apprise, value: [
            'enabled' => true,
            'url' => 'https://example.com/apprise',
            'token' => 'test-token',
        ]);

        $user = User::factory()->create();

        $settings = AppriseChannel::getSettings($user);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('https://example.com/apprise', $settings['url']);
        $this->assertEquals('test-token', $settings['token']);
        $this->assertEquals('all', $settings['tags']);

        // Update user settings.
        $user->update([
            'settings' => [
                'notifications' => [
                    'apprise' => [
                        'enabled' => true,
                        'tags' => 'some,tag',
                        'token' => 'override-token',
                    ],
                ],
            ],
        ]);

        $settings = AppriseChannel::getSettings($user);
        $this->assertTrue($settings['enabled']);
        $this->assertEquals('https://example.com/apprise', $settings['url']);
        $this->assertEquals('override-token', $settings['token']);
        $this->assertEquals('some,tag', $settings['tags']);
    }

    public function test_make_url()
    {
        $this->assertSame(
            'https://example.com/apprise/notify/test-token',
            AppriseChannel::makeUrl('https://example.com/apprise', 'test-token')
        );
    }

    public function test_send_notification()
    {
        NotificationsHelper::setSetting(NotificationMethods::Apprise, value: [
            'enabled' => true,
            'url' => 'https://apprise.example.com',
            'token' => 'apprise',
        ]);

        $user = User::factory()->create([
            'settings' => [
                'notifications' => [
                    'apprise' => [
                        'enabled' => true,
                        'tags' => 'all',
                        'token' => 'override-token',
                    ],
                ],
            ],
        ]);

        $product = Product::factory()->addUrlsAndPrices()->create([
            'title' => 'Notif product',
        ]);

        $notification = new PriceAlertNotification($product->urls()->first());
        $message = $notification->toApprise($user);

        $postUrl = 'apprise.example.com/notify/override-token';

        Http::fake([
            $postUrl => Http::response(),
        ]);

        (new AppriseChannel)->send($user, $notification);

        Http::assertSent(function (Request $request) use ($message, $postUrl) {
            return $request->url() === 'https://'.$postUrl &&
                   $request->method() === 'POST' &&
                   $request['title'] === $message->title &&
                   $request['body'] === $message->content &&
                   $request['tags'] === 'all';
        });
    }

    public function test_disable_service()
    {
        $user = User::factory()->withNotificationSettings([
            'apprise' => [
                'enabled' => true,
            ],
        ])->createOne();

        $this->assertTrue(
            in_array(NotificationMethods::Apprise->getChannel(), NotificationsHelper::getEnabledChannels($user)->all())
        );

        $disabledUser = User::factory()->withNotificationSettings([
            NotificationMethods::Apprise->value => [
                'enabled' => false,
            ],
        ])->createOne();
        $this->assertFalse(
            in_array(NotificationMethods::Apprise->getChannel(), NotificationsHelper::getEnabledChannels($disabledUser)->all())
        );

        NotificationsHelper::setSetting(NotificationMethods::Apprise, value: [
            'enabled' => false,
            'url' => 'https://gotify.test',
            'token' => 'test-token',
        ]);
        $this->assertFalse(
            in_array(NotificationMethods::Apprise->getChannel(), NotificationsHelper::getEnabledChannels($user)->all())
        );
    }
}
