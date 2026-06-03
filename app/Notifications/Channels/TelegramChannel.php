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
 * Sends notifications via a Telegram bot.
 *
 * The bot token is configured once at the app level (it is shared by every
 * user), while each user provides their own chat id so messages are routed to
 * their personal Telegram chat. This mirrors the Pushover pattern (global app
 * token + per-user user key).
 *
 * @see https://core.telegram.org/bots/api#sendmessage
 */
class TelegramChannel
{
    /**
     * @param  User  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $settings = self::getSettings($notifiable);

        // Without a bot token (app level) and chat id (per user) there is
        // nowhere to deliver the message, so skip silently.
        if (empty($settings['bot_token']) || empty($settings['chat_id'])) {
            return;
        }

        $response = self::sendRequest(
            self::makeUrl($settings['bot_token']),
            $settings['chat_id'],
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
        $settings = NotificationsHelper::getSettings(NotificationMethods::Telegram);
        $settings['chat_id'] = $notifiable->getNotificationSettings(NotificationMethods::Telegram, 'chat_id', '');

        return $settings;
    }

    public static function makeUrl(string $botToken): string
    {
        return 'https://api.telegram.org/bot'.$botToken.'/sendMessage';
    }

    public static function sendRequest(string $apiUrl, string $chatId, string $title, string $message, string $url = ''): Response
    {
        return Http::post($apiUrl, [
            'chat_id' => $chatId,
            'text' => self::formatText($title, $message, $url),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ]);
    }

    /**
     * Build the HTML message body Telegram will render.
     */
    public static function formatText(string $title, string $message, string $url = ''): string
    {
        $text = '<b>'.e($title).'</b>';

        if ($message !== '') {
            $text .= "\n\n".e($message);
        }

        if ($url !== '') {
            $text .= "\n\n".'<a href="'.e($url).'">'.__('View product').'</a>';
        }

        return $text;
    }
}
