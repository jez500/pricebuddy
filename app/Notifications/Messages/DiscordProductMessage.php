<?php

namespace App\Notifications\Messages;

use App\Models\Price;
use App\Models\Url;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Str;

/**
 * Product context for Discord embeds, without changing other delivery channels.
 */
class DiscordProductMessage extends GenericNotificationMessage
{
    public ?string $thumbnailUrl = null;

    public string $historyUrl = '';

    /** @var array<int, array{name: string, value: string, inline: bool}> */
    public array $fields = [];

    /**
     * Compare the two latest prices from this listing, using its store's currency.
     */
    public static function forPriceAlert(Url $url): self
    {
        $message = self::forProduct($url, __('Price alert'));
        $prices = $url->prices()->reorder()->orderByDesc('created_at')->orderByDesc('id')->limit(2)->get();
        /** @var Price|null $current */
        $current = $prices->first();
        /** @var Price|null $previous */
        $previous = $prices->get(1);

        if ($current) {
            $message->field(__('Current price'), self::formatPrice($url, (float) $current->price));
        }

        if ($current && $previous) {
            $message->field(__('Previous price'), self::formatPrice($url, (float) $previous->price));
            $difference = (float) $current->price - (float) $previous->price;
            $change = __('Unchanged');

            if (abs($difference) >= 0.005) {
                $change = ($difference < 0 ? __('Down') : __('Up')).' '.self::formatPrice($url, abs($difference));

                if ((float) $previous->price > 0) {
                    $change .= ' ('.round(abs($difference) / (float) $previous->price * 100, 2).'%)';
                }
            }

            $message->field(__('Price change'), $change);
        }

        if ($url->product?->notify_price > 0) {
            $message->field(__('Your target price'), self::formatPrice($url, (float) $url->product->notify_price));
        }

        if ($url->product?->notify_percent > 0) {
            $message->field(__('Your drop target'), __(':percent% below the first recorded price', [
                'percent' => (float) $url->product->notify_percent,
            ]), false);
        }

        return $message;
    }

    /**
     * Stock events can precede the new price record, so label historical prices explicitly.
     */
    public static function forStockAlert(Url $url): self
    {
        $message = self::forProduct($url, __('Back in stock'));
        /** @var Price|null $price */
        $price = $url->prices()->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();

        if ($price) {
            $message->field(__('Last recorded price'), self::formatPrice($url, (float) $price->price));
            $message->field(__('Price recorded'), '<t:'.$price->created_at->getTimestamp().':f>', false);
        }

        return $message;
    }

    /**
     * Add structured details while keeping Discord's field limits in one place.
     */
    public function field(string $name, string $value, bool $inline = true): self
    {
        $this->fields[] = ['name' => $name, 'value' => $value, 'inline' => $inline];

        return $this;
    }

    /**
     * Additional embed properties consumed only by the Discord channel.
     *
     * @return array<string, mixed>
     */
    public function toEmbedDetails(): array
    {
        $fields = $this->fields;
        $links = '[View at store](<'.$this->url.'>)';

        if ($this->historyUrl !== '') {
            $links .= ' · [Price history](<'.$this->historyUrl.'>)';
        }

        $fields[] = ['name' => __('Links'), 'value' => $links, 'inline' => false];
        $remaining = 6000 - mb_strlen(Str::limit($this->title, 256, ''))
            - mb_strlen(Str::limit($this->content, 4096, '')) - mb_strlen('PriceBuddy');
        $embedFields = [];

        foreach (array_slice($fields, 0, 25) as $field) {
            if ($remaining < 2) {
                break;
            }

            $name = Str::limit($field['name'], min(256, $remaining - 1), '');
            $value = Str::limit($field['value'], min(1024, $remaining - mb_strlen($name)), '');

            if ($name === '' || $value === '') {
                continue;
            }

            $embedFields[] = ['name' => $name, 'value' => $value, 'inline' => $field['inline']];
            $remaining -= mb_strlen($name) + mb_strlen($value);
        }

        $embed = [
            'fields' => $embedFields,
            'footer' => ['text' => 'PriceBuddy'],
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->thumbnailUrl && filter_var($this->thumbnailUrl, FILTER_VALIDATE_URL)
            && in_array(parse_url($this->thumbnailUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $embed['thumbnail'] = ['url' => $this->thumbnailUrl];
        }

        return $embed;
    }

    /**
     * Keep full product names in the description rather than the short card title.
     */
    protected static function forProduct(Url $url, string $title): self
    {
        $message = new self($url->product->title ?? __('Unknown product'));
        $message->title($title)->url($url->buy_url);
        $message->thumbnailUrl = $url->product?->image;
        $message->historyUrl = $url->product ? url($url->product_url) : '';
        $message->field(__('Store'), $url->store_name);
        $message->field(__('Availability'), $url->getAvailabilityStatus()?->getLabel() ?? __('In Stock'));

        return $message;
    }

    protected static function formatPrice(Url $url, float $price): string
    {
        return CurrencyHelper::toString($price, locale: $url->store?->locale, iso: $url->store?->currency);
    }
}
