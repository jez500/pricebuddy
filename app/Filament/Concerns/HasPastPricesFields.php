<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Get;
use Illuminate\Support\Carbon;

/**
 * "Apply the new settings to": all past prices, prices since a date, or new prices only.
 * Shown once a setting that changes how prices are worked out has been changed, on the
 * store form (price adjustments) and the listing forms (ignore shipping).
 */
trait HasPastPricesFields
{
    public const string PAST_PRICES_APPLY = 'price_adjustments_apply';

    public const string PAST_PRICES_SINCE = 'price_adjustments_since';

    /**
     * @param  \Closure  $changed  whether the setting was changed, with the usual Filament injections
     * @return array<int, Component>
     */
    protected static function makePastPricesFields(\Closure $changed, bool $dehydrated = true): array
    {
        return [
            Radio::make(self::PAST_PRICES_APPLY)
                ->label('Apply the new settings to')
                ->options([
                    'all' => 'All past prices',
                    'since' => 'Prices since a date',
                    'new' => 'New prices only',
                ])
                ->afterStateHydrated(fn (Radio $component, ?string $state) => $component->state($state ?? 'all'))
                ->live()
                ->dehydrated($dehydrated)
                ->visible($changed)
                ->columnSpanFull(),
            DatePicker::make(self::PAST_PRICES_SINCE)
                ->label('Since')
                ->maxDate(now())
                ->required()
                ->dehydrated($dehydrated)
                ->visible(fn (Get $get, Component $component): bool => $get(self::PAST_PRICES_APPLY) === 'since'
                    && (bool) $component->evaluate($changed)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function shouldRecalculatePastPrices(array $data): bool
    {
        return ($data[self::PAST_PRICES_APPLY] ?? 'all') !== 'new';
    }

    /**
     * Midnight of the chosen date, or null for all past prices.
     *
     * @param  array<string, mixed>  $data
     */
    public static function pastPricesSince(array $data): ?Carbon
    {
        $since = $data[self::PAST_PRICES_SINCE] ?? null;

        return ($data[self::PAST_PRICES_APPLY] ?? 'all') === 'since' && filled($since)
            ? Carbon::parse($since)->startOfDay()
            : null;
    }
}
