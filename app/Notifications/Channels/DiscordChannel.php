<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications to a Discord channel via an incoming webhook.
 *
 * A webhook url points at a single Discord channel, so it doubles as the
 * routing target. An optional default webhook can be set at the app level and
 * each user may override it with their own (mirrors the Apprise token-override
 * pattern).
 *
 * @see https://discord.com/developers/docs/resources/webhook#execute-webhook
 */
class DiscordChannel
{
    /**
     * Green embed colour used for price-drop alerts.
     */
    public const COLOR_PRICE_DROP = 0x57F287;

    /**
     * @param  User  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        $message = $notification->toDiscord($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $webhookUrl = self::getSettings($notifiable)['webhook_url'] ?? '';

        if (empty($webhookUrl)) {
            return;
        }

        $response = self::sendRequest(
            $webhookUrl,
            $message->title,
            $message->content,
            $message->url
        );

        $response->throw();
    }

    /**
     * @param  User  $notifiable
     */
    public static function getSettings($notifiable): array
    {
        $settings = NotificationsHelper::getSettings(NotificationMethods::Discord);
        $userWebhook = $notifiable->getNotificationSettings(NotificationMethods::Discord, 'webhook_url', '');
        $settings['webhook_url'] = empty($userWebhook) ? ($settings['webhook_url'] ?? '') : $userWebhook;

        return $settings;
    }

    public static function sendRequest(string $webhookUrl, string $title, string $message, string $url = '', int $color = self::COLOR_PRICE_DROP): Response
    {
        $embed = [
            'title' => $title,
            'description' => $message,
            'color' => $color,
        ];

        if ($url !== '') {
            $embed['url'] = $url;
        }

        return Http::post($webhookUrl, [
            'embeds' => [$embed],
        ]);
    }
}
