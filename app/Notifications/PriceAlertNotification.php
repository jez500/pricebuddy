<?php

namespace App\Notifications;

use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\DiscordProductMessage;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\NotificationsHelper;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Pushover\PushoverMessage;

class PriceAlertNotification extends Notification
{
    // use Queueable;

    protected string $ctaText = 'View price history';

    protected ?Product $product;

    /**
     * Create a new notification instance.
     *
     * @param  ?float  $previousPrice  set for a price change alert, so the message can say "increase" or "drop"
     * @param  ?Price  $price  the price that triggered the alert
     */
    public function __construct(protected Url $url, protected ?float $previousPrice = null, protected ?Price $price = null)
    {
        $this->product = $url->product;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return NotificationsHelper::getEnabledChannels($notifiable)
            ->push('database')
            ->toArray();
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getTitle())
            ->markdown('mail.price-change-notification', $this->toArray($notifiable));
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title($this->getTitle())
            ->body($this->getSummary())
            ->status('success')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->getUrl(), PHP_URL_PATH), false)
                    ->label('View product'),
                Action::make('buy')
                    ->url($this->url->buy_url, true)
                    ->label('Buy'),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Get the array representation of the notification.
     *
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
            'newPrice' => $this->getPriceFormatted(),
            'averagePrice' => data_get($this->product?->price_aggregates, 'avg', '$0.00'),
        ];
    }

    public function toPushover($notifiable)
    {
        return PushoverMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl(), $this->ctaText);
    }

    public function toGotify($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    public function toApprise($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    public function toTelegram($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    public function toDiscord($notifiable): DiscordProductMessage
    {
        return DiscordProductMessage::forPriceAlert($this->url, $this->price);
    }

    public function toNtfy($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    protected function getTitle(): string
    {
        return 'Price '.$this->getChangeType().': '.$this->url->product_name_short.' ('.$this->getPriceFormatted().')';
    }

    protected function getSummary(): string
    {
        $summary = $this->url->store_name.' has had a price '.$this->getChangeType().' for '.
            $this->url->product_name_short.' - '.$this->getPriceFormatted();

        if (! is_null($this->previousPrice)) {
            $summary .= ' (was '.CurrencyHelper::toString(
                $this->previousPrice,
                locale: $this->url->store?->locale,
                iso: $this->url->store?->currency
            ).')';
        }

        return $summary;
    }

    /**
     * The price that triggered the alert, or the latest price when none was given.
     */
    protected function getPriceFormatted(): string
    {
        if (is_null($this->price)) {
            return $this->url->latest_price_formatted;
        }

        return CurrencyHelper::toString(
            $this->price->price,
            locale: $this->url->store?->locale,
            iso: $this->url->store?->currency
        );
    }

    protected function getChangeType(): string
    {
        // A newer price can be saved before the notification is rendered.
        /** @var ?Price $latest */
        $latest = $this->price ?? $this->url->prices()->reorder('id', 'desc')->first();
        $latestPrice = $latest?->price;

        return ! is_null($this->previousPrice) && ! is_null($latestPrice) && (float) $latestPrice > $this->previousPrice
            ? 'increase'
            : 'drop';
    }

    protected function getUrl(): string
    {
        return $this->url->buy_url;
    }
}
