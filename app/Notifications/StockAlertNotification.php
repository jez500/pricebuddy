<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Pushover\PushoverMessage;

/**
 * Notifies a user that a product they track is back in stock.
 *
 * Reuses every notification channel configured for price alerts (mail,
 * database, Pushover, Gotify, Apprise, Telegram, Discord, ntfy).
 */
class StockAlertNotification extends Notification
{
    protected ?Product $product;

    public function __construct(protected Url $url)
    {
        $this->product = $url->product;
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return NotificationsHelper::getEnabledChannels($notifiable)
            ->push('database')
            ->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getTitle())
            ->markdown('mail.stock-change-notification', $this->toArray($notifiable));
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title($this->getTitle())
            ->body($this->getSummary())
            ->status('success')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->url->product_url, PHP_URL_PATH), false)
                    ->label('View product'),
                Action::make('buy')
                    ->url($this->url->buy_url, true)
                    ->label('Buy'),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'summary' => $this->getSummary(),
            'buyUrl' => $this->url->buy_url,
            'buyText' => __('Buy now from :store', ['store' => $this->url->store_name]),
            'storeName' => $this->url->store_name,
            'productUrl' => $this->getUrl(),
            'productName' => Str::limit(($this->product->title ?? 'Unknown product'), 100),
            'imgUrl' => $this->product?->image,
        ];
    }

    public function toPushover($notifiable)
    {
        return PushoverMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->url->buy_url, __('View product'));
    }

    public function toGotify($notifiable)
    {
        return $this->genericMessage();
    }

    public function toApprise($notifiable)
    {
        return $this->genericMessage();
    }

    public function toTelegram($notifiable)
    {
        return $this->genericMessage();
    }

    public function toDiscord($notifiable)
    {
        return $this->genericMessage();
    }

    public function toNtfy($notifiable)
    {
        return $this->genericMessage();
    }

    protected function genericMessage(): GenericNotificationMessage
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->url->buy_url)
            ->priority(5);
    }

    protected function getTitle(): string
    {
        return 'Back in stock: '.$this->url->product_name_short;
    }

    protected function getSummary(): string
    {
        return $this->url->store_name.' has '.$this->url->product_name_short.' back in stock';
    }

    protected function getUrl(): string
    {
        return $this->url->product_url;
    }
}
