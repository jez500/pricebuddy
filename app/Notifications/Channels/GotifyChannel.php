<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use App\Notifications\Messages\GotifyMessage;

class GotifyChannel
{
    public function send($notifiable, Notification $notification): void
    {
        if (! $notifiable->routeNotificationFor('gotify')) {
            return;
        }

        $message = $notification->toGotify($notifiable);

        if (! $message instanceof GotifyMessage) {
            return;
        }

        $response = Http::post($this->getUrl($notifiable), [
            'title' => $message->title,
            'message' => $message->content,
            'priority' => $message->priority,
            'extras' => [
                'client::notification' => [
                    'click' => $message->url,
                ],
            ],
        ]);

        $response->throw();
    }

    protected function getUrl($notifiable): string
    {
        $settings = data_get($notifiable->settings, 'notifications.gotify') ?? [];
        $url = $settings['url'] ?? '';
        $token = $settings['token'] ?? '';

        return rtrim($url, '/').'/message?token='.$token;
    }
}
