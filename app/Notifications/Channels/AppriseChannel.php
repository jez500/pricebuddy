<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class AppriseChannel
{
    /**
     * @param  User  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toApprise')) {
            return;
        }

        $message = $notification->toApprise($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $response = self::sendRequest(
            $this->getUrl($notifiable),
            $message->title,
            $message->content,
            $message->content
        );

        $response->throw();
    }

    /**
     * Get the URL for the notifiable.
     *
     * @param  User  $notifiable
     */
    protected function getUrl($notifiable): string
    {
        $settings = self::getSettings($notifiable);

        return self::makeUrl($settings['url'], $settings['token']);
    }

    public static function getSettings($notifiable): array
    {
        $settings = NotificationsHelper::getSettings(NotificationMethods::Apprise);
        $userSettings = $notifiable->getNotificationSettings(NotificationMethods::Apprise);
        $settings['tags'] = empty($userSettings['tags']) ? 'all' : $userSettings['tags'];
        $settings['token'] = empty($userSettings['token']) ? ($settings['token'] ?? '') : $userSettings['token'];

        return $settings;
    }

    public static function makeUrl(string $apiUrl, string $token): string
    {
        return rtrim($apiUrl, '/').'/notify/'.$token;
    }

    public static function sendRequest(string $apiUrl, string $title, string $message, string $url, string $tag = 'all'): Response
    {
        return Http::post($apiUrl, [
            'title' => $title,
            'body' => $message,
            'tags' => $tag,
        ]);
    }
}
