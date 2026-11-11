<?php

namespace App\Dto;

use App\Services\Helpers\CurrencyHelper;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A store's price adjustments: how to turn the price shown on the page into the price
 * the user pays. Stored in the store's `settings.price_adjustments`.
 *
 * The tax fields swap the tax the page price includes for the tax the user pays, so a
 * page showing 19% VAT to a buyer who pays 23% is 19 and 23. Adding tax to a price
 * shown without it is 0 and 23; removing it is 23 and 0.
 *
 * @implements Arrayable<string, float|bool>
 */
class PriceAdjustmentsDto implements Arrayable
{
    public function __construct(
        public float $taxIncludedPercent = 0,
        public float $taxPercent = 0,
        public float $shippingCost = 0,
        public float $freeShippingFrom = 0,
        public bool $taxOnShipping = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(
            taxIncludedPercent: (float) ($data['tax_included_percent'] ?? 0),
            taxPercent: (float) ($data['tax_percent'] ?? 0),
            shippingCost: (float) ($data['shipping_cost'] ?? 0),
            freeShippingFrom: (float) ($data['free_shipping_from'] ?? 0),
            taxOnShipping: (bool) ($data['tax_on_shipping'] ?? false),
        );
    }

    /**
     * @return array{tax_included_percent: float, tax_percent: float, shipping_cost: float, free_shipping_from: float, tax_on_shipping: bool}
     */
    public function toArray(): array
    {
        return [
            'tax_included_percent' => $this->taxIncludedPercent,
            'tax_percent' => $this->taxPercent,
            'shipping_cost' => $this->shippingCost,
            'free_shipping_from' => $this->freeShippingFrom,
            'tax_on_shipping' => $this->taxOnShipping,
        ];
    }

    public function taxFactor(): float
    {
        return (1 + $this->taxPercent / 100) / (1 + $this->taxIncludedPercent / 100);
    }

    /**
     * True when these settings leave every price as it is.
     */
    public function isEmpty(): bool
    {
        return abs($this->taxFactor() - 1) < 1e-9 && $this->shippingCost == 0;
    }

    /**
     * The flat shipping fee for an item, which is free once the price after tax reaches
     * the free-shipping threshold.
     */
    public function flatShippingFor(float $priceAfterTax): float
    {
        if ($this->freeShippingFrom > 0 && round($priceAfterTax, 2) >= round($this->freeShippingFrom, 2)) {
            return 0.0;
        }

        return $this->shippingCost;
    }

    /**
     * The price the user pays for a page price. Shipping read from the page replaces the
     * flat shipping fee when given. Ignored shipping is left out of the price but kept, so
     * the price can be worked out again if the listing stops ignoring it.
     */
    public function apply(float $pagePrice, ?float $pageShipping = null, bool $ignoreShipping = false): AdjustedPriceDto
    {
        $factor = $this->taxFactor();
        $shipping = round($pageShipping ?? $this->flatShippingFor($pagePrice * $factor), 2);
        $counted = $ignoreShipping ? 0.0 : $shipping;

        $total = round($this->taxOnShipping
            ? ($pagePrice + $counted) * $factor
            : $pagePrice * $factor + $counted, 2);

        return new AdjustedPriceDto(
            pagePrice: $pagePrice,
            tax: round($total - $pagePrice - $counted, 2),
            shipping: $counted,
            pageShipping: $pageShipping,
            uncountedShipping: $ignoreShipping ? $shipping : 0.0,
            total: $total,
            settings: $this,
        );
    }

    /**
     * The price the user pays for a page price, or null when nothing is adjusted. Shipping
     * read from the page only counts when the store reads it ($readsPageShipping).
     */
    public function adjust(float $pagePrice, ?float $pageShipping, bool $readsPageShipping, bool $ignoreShipping = false): ?AdjustedPriceDto
    {
        if ($pagePrice <= 0 || ($this->isEmpty() && ! $readsPageShipping)) {
            return null;
        }

        return $this->apply($pagePrice, $readsPageShipping ? $pageShipping : null, $ignoreShipping);
    }

    /**
     * Read a scraped shipping value. Null when nothing was found or the amount cannot be
     * read, 0 when the value has no number in it (e.g. "FREE" or "Envío gratis").
     */
    public static function parseShipping(?string $value, ?string $locale = null, ?string $currency = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/\d/', $value)) {
            return 0.0;
        }

        return CurrencyHelper::toFloat($value, locale: $locale, iso: $currency);
    }
}
