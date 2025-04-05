<?php

namespace Tests\Unit\Notifications\Channels;

use App\Models\User;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\Messages\GotifyMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GotifyChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_gotify_channel_sends_notification_correctly()
    {
        $user = User::factory()->withGotifySettings('https://gotify.test', 'test-token')->make();
        $notification = new TestGotifyNotification();
        $message = $notification->toGotify($user);

        Http::fake([
            'https://gotify.test/message?token=test-token' => Http::response(null, 200),
        ]);

        $channel = new GotifyChannel();
        $channel->send($user, $notification);

        Http::assertSent(function (Request $request) use ($message) {
            return $request->url() === 'https://gotify.test/message?token=test-token' &&
                   $request->method() === 'POST' &&
                   $request['title'] === $message->title &&
                   $request['message'] === $message->content &&
                   $request['priority'] === $message->priority;
        });
    }

    public function test_gotify_channel_handles_missing_routing_information()
    {
        $user = User::factory()->make(); // No Gotify settings
        $notification = new TestGotifyNotification();

        // No HTTP call should be made
        Http::fake();

        $channel = new GotifyChannel();
        $channel->send($user, $notification);

        Http::assertNothingSent();
    }

    public function test_gotify_channel_handles_http_errors()
    {
        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $user = User::factory()->withGotifySettings('https://gotify.test', 'test-token')->make();
        $notification = new TestGotifyNotification();

        Http::fake([
            'https://gotify.test/message?token=test-token' => Http::response('Server Error', 500),
        ]);

        $channel = new GotifyChannel();
        $channel->send($user, $notification);
    }
}

// Dummy notification for testing
class TestGotifyNotification extends Notification
{
    public function via($notifiable)
    {
        return [GotifyChannel::class];
    }

    public function toGotify($notifiable)
    {
        // Define expected values matching the assertion
        $expectedTitle = 'Test Notification Title'; // Example title
        $expectedPriority = 5; // Example priority
        $expectedUrl = 'https://example.com/click'; // Example URL

        return GotifyMessage::create()
            ->title($expectedTitle)
            ->content('Test Message Content')
            ->priority($expectedPriority)
            ->url($expectedUrl);
    }
}
