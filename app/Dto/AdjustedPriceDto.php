<?php

namespace App\Dto;

use App\Services\Helpers\CurrencyHelper;

/**
 * A page price after a store's price adjustments: the parts that add up to the price the
 * user pays. The page price and the total live in the price row's `scraped_price` and
 * `price` columns; the rest is stored in its `price_adjustments` column.
 */
class AdjustedPriceDto
{
    /**
     * @param  float  $shipping  the shipping counted in the total
     * @param  ?float  $pageShipping  the shipping read from the page, null when none was
     * @param  float  $uncountedShipping  shipping the store would charge but the listing ignores
     */
    public function __construct(
        public float $pagePrice,
        public float $tax,
        public float $shipping,
        public ?float $pageShipping,
        public float $uncountedShipping,
        public float $total,
        public PriceAdjustmentsDto $settings,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data  a price row's `price_adjustments`
     */
    public static function fromStorage(?array $data, float $pagePrice, float $total): ?self
    {
        if (empty($data)) {
            return null;
        }

        return new self(
            pagePrice: $pagePrice,
            tax: (float) ($data['tax'] ?? 0),
            shipping: (float) ($data['shipping'] ?? 0),
            pageShipping: isset($data['page_shipping']) ? (float) $data['page_shipping'] : null,
            uncountedShipping: (float) ($data['uncounted_shipping'] ?? 0),
            total: $total,
            settings: PriceAdjustmentsDto::fromArray($data['settings'] ?? null),
        );
    }

    /**
     * @return array{tax: float, shipping: float, page_shipping: ?float, uncounted_shipping: float, settings: array<string, float|bool>}
     */
    public function toStorageArray(): array
    {
        return [
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'page_shipping' => $this->pageShipping,
            'uncounted_shipping' => $this->uncountedShipping,
            'settings' => $this->settings->toArray(),
        ];
    }

    /**
     * Whether the price the user pays differs from the page price.
     */
    public function isAdjusted(): bool
    {
        return abs($this->tax) >= 0.005 || abs($this->shipping) >= 0.005;
    }

    /**
     * Whether shipping the store charges was left out of the price.
     */
    public function isShippingNotCounted(): bool
    {
        return $this->uncountedShipping >= 0.005;
    }

    /**
     * @return array{page: float, tax: float, shipping: float, shipping_not_counted: bool}
     */
    public function toBreakdownArray(): array
    {
        return [
            'page' => $this->pagePrice,
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'shipping_not_counted' => $this->isShippingNotCounted(),
        ];
    }

    /**
     * "€324.80 + €10.92 tax + €7.65 shipping". Parts that are zero are left out.
     */
    public function describe(?string $locale = null, ?string $currency = null): string
    {
        return self::describeParts($this->pagePrice, $this->tax, $this->shipping, $locale, $currency, $this->isShippingNotCounted());
    }

    public static function describeParts(float $page, float $tax, float $shipping, ?string $locale = null, ?string $currency = null, bool $shippingNotCounted = false): string
    {
        $money = fn (float $amount): string => CurrencyHelper::toString(abs($amount), locale: $locale, iso: $currency);

        $text = $money($page);

        if (abs($tax) >= 0.005) {
            $text .= ($tax > 0 ? ' + ' : ' − ').__(':amount tax', ['amount' => $money($tax)]);
        }

        if (abs($shipping) >= 0.005) {
            $text .= ' + '.__(':amount shipping', ['amount' => $money($shipping)]);
        }

        if ($shippingNotCounted) {
            $text .= ' · '.__('shipping not counted');
        }

        return $text;
    }
}
