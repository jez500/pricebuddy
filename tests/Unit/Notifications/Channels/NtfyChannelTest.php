<?php

namespace Tests\Unit\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\PriceAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NtfyChannelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        NotificationsHelper::setSetting(NotificationMethods::Ntfy, value: [
            'enabled' => true,
            'server_url' => 'https://ntfy.example.com',
        ]);

        $this->user = User::factory()->withNotificationSettings([
            NotificationMethods::Ntfy->value => [
                'enabled' => true,
                'topic' => 'pricebuddy-test',
            ],
        ])->createOne();
    }

    public function test_get_settings_merges_server_and_user_topic()
    {
        $settings = NtfyChannel::getSettings($this->user);
        $this->assertTrue($settings['enabled']);
        $this->assertEquals('https://ntfy.example.com', $settings['server_url']);
        $this->assertEquals('pricebuddy-test', $settings['topic']);
    }

    public function test_defaults_to_public_server_when_blank()
    {
        NotificationsHelper::setSetting(NotificationMethods::Ntfy, value: [
            'enabled' => true,
        ]);

        $this->assertEquals(NtfyChannel::DEFAULT_SERVER, NtfyChannel::getSettings($this->user)['server_url']);
    }

    public function test_make_url()
    {
        $this->assertSame(
            'https://ntfy.example.com/pricebuddy-test',
            NtfyChannel::makeUrl('https://ntfy.example.com/', '/pricebuddy-test')
        );
    }

    public function test_send_notification()
    {
        $product = Product::factory()->addUrlsAndPrices()->create([
            'title' => 'Notif product',
        ]);

        $notification = new PriceAlertNotification($product->urls()->first());
        $message = $notification->toNtfy($this->user);

        $postUrl = 'ntfy.example.com/pricebuddy-test';

        Http::fake([
            $postUrl => Http::response(),
        ]);

        (new NtfyChannel)->send($this->user, $notification);

        Http::assertSent(function (Request $request) use ($message, $postUrl) {
            return $request->url() === 'https://'.$postUrl &&
                $request->method() === 'POST' &&
                $request->body() === $message->content &&
                $request->header('Title')[0] === $message->title &&
                $request->header('Click')[0] === $message->url;
        });
    }

    public function test_send_with_basic_auth()
    {
        NotificationsHelper::setSetting(NotificationMethods::Ntfy, value: [
            'enabled' => true,
            'server_url' => 'https://ntfy.example.com',
            'username' => 'user',
            'password' => 'secret',
        ]);

        $product = Product::factory()->addUrlsAndPrices()->create();
        $notification = new PriceAlertNotification($product->urls()->first());

        Http::fake([
            'ntfy.example.com/pricebuddy-test' => Http::response(),
        ]);

        (new NtfyChannel)->send($this->user, $notification);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret'));
        });
    }

    public function test_no_request_without_topic()
    {
        $userWithoutTopic = User::factory()->withNotificationSettings([
            NotificationMethods::Ntfy->value => ['enabled' => true],
        ])->createOne();

        $product = Product::factory()->addUrlsAndPrices()->create();
        $notification = new PriceAlertNotification($product->urls()->first());

        (new NtfyChannel)->send($userWithoutTopic, $notification);

        Http::assertNothingSent();
    }

    public function test_disable_service()
    {
        $this->assertTrue(
            in_array(NotificationMethods::Ntfy->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );

        NotificationsHelper::setSetting(NotificationMethods::Ntfy, value: [
            'enabled' => false,
        ]);
        $this->assertFalse(
            in_array(NotificationMethods::Ntfy->getChannel(), NotificationsHelper::getEnabledChannels($this->user)->all())
        );
    }
}
