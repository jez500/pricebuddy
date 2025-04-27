<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;

class GotifyChannel
{
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toGotify')) {
            return;
        }

        $message = $notification->toGotify($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $response = self::sendRequest(
            $this->getUrl($notifiable),
            $message->title,
            $message->content,
            $message->url,
            $message->priority
        );

        $response->throw();
    }

    protected function getUrl($notifiable): string
    {
        $settings = self::getSettings($notifiable);

        return self::makeUrl($settings['url'], $settings['token']);
    }

    public static function makeUrl(string $apiUrl, string $token): string
    {
        return Uri::of(rtrim($apiUrl, '/').'/message')
            ->withQuery(['token' => $token])
            ->value();
    }

    public static function getSettings($notifiable): array
    {
        return NotificationsHelper::getSettings(NotificationMethods::Gotify);
    }

    public static function sendRequest(string $apiUrl, string $title, string $message, string $url, int $priority = 0): Response
    {
        return Http::post($apiUrl, [
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'extras' => [
                'client::notification' => [
                    'click' => $url,
                ],
            ],
        ]);
    }
}
