<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications via ntfy (https://ntfy.sh or a self-hosted server).
 *
 * The server url + optional basic-auth credentials are configured at the app
 * level (defaulting to the public ntfy.sh), while each user subscribes to their
 * own topic. A message is delivered by POSTing the body to {server}/{topic}.
 *
 * @see https://docs.ntfy.sh/publish/
 */
class NtfyChannel
{
    public const DEFAULT_SERVER = 'https://ntfy.sh';

    public const DEFAULT_TAGS = 'moneybag';

    /**
     * @param  User  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toNtfy')) {
            return;
        }

        $message = $notification->toNtfy($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $settings = self::getSettings($notifiable);

        if (empty($settings['topic'])) {
            return;
        }

        $response = self::sendRequest(
            self::makeUrl($settings['server_url'], $settings['topic']),
            $message->title,
            $message->content,
            $message->url,
            $settings['username'] ?? '',
            $settings['password'] ?? ''
        );

        $response->throw();
    }

    /**
     * @param  User  $notifiable
     */
    public static function getSettings($notifiable): array
    {
        $settings = NotificationsHelper::getSettings(NotificationMethods::Ntfy);
        $settings['server_url'] = empty($settings['server_url']) ? self::DEFAULT_SERVER : $settings['server_url'];
        $settings['topic'] = $notifiable->getNotificationSettings(NotificationMethods::Ntfy, 'topic', '');

        return $settings;
    }

    public static function makeUrl(string $serverUrl, string $topic): string
    {
        return rtrim($serverUrl, '/').'/'.ltrim($topic, '/');
    }

    public static function sendRequest(
        string $apiUrl,
        string $title,
        string $message,
        string $url = '',
        string $username = '',
        string $password = ''
    ): Response {
        $headers = [
            'Title' => $title,
            'Tags' => self::DEFAULT_TAGS,
        ];

        if ($url !== '') {
            $headers['Click'] = $url;
        }

        $request = Http::withHeaders($headers);

        // Optional basic auth for protected (typically self-hosted) servers.
        if ($username !== '' && $password !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return self::withBody($request, $message)->post($apiUrl);
    }

    /**
     * ntfy expects the notification text as the raw request body.
     */
    protected static function withBody(PendingRequest $request, string $message): PendingRequest
    {
        return $request->withBody($message, 'text/plain');
    }
}
