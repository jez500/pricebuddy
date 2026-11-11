<?php

namespace App\Filament\Concerns;

use App\Models\Url;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;

/**
 * The listing's "Ignore shipping" switch, for the two forms that edit a listing. Shown
 * when the listing's store adds shipping, and while it is on, so it can be turned off.
 */
trait HasIgnoreShippingFields
{
    use HasPastPricesFields;

    /**
     * @return array<int, Component>
     */
    protected static function makeIgnoreShippingFields(?Url $url): array
    {
        if (! $url || (! $url->store?->hasShipping() && ! $url->ignore_shipping)) {
            return [];
        }

        return [
            Toggle::make('ignore_shipping')
                ->label('Ignore shipping')
                ->helperText('Leave shipping out of this listing\'s price, e.g. when you combine orders at this store. Tax adjustments still apply.')
                ->live(),
            ...self::makePastPricesFields(fn (Get $get): bool => (bool) $get('ignore_shipping') !== (bool) $url->ignore_shipping),
        ];
    }

    /**
     * Save a changed "Ignore shipping" and recalculate past prices as chosen. Call it
     * before syncStoredPricesForCurrentFactor(), which then sets every unit price.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function saveIgnoreShipping(Url $url, array $data): void
    {
        if (! array_key_exists('ignore_shipping', $data) || (bool) $data['ignore_shipping'] === (bool) $url->ignore_shipping) {
            return;
        }

        $url->update(['ignore_shipping' => (bool) $data['ignore_shipping']]);

        if (self::shouldRecalculatePastPrices($data)) {
            $url->recalculatePrices(self::pastPricesSince($data));
        }
    }
}
