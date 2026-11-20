<?php

namespace App\Filament\Forms;

use App\Actions\AddUrlsToProductAction;
use App\Dto\VariantDto;
use App\Enums\Icons;
use App\Services\ScrapeUrl;
use Closure;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Form fields to choose the variant a product URL tracks, eg size "Small". Shared by
 * every form that adds or edits a URL.
 *
 * Values can be typed, or picked from the variants detected on the page. Picking also
 * pins the variant's id (eg SKU) so it is matched exactly. The form's URL field should
 * use resetOnUrlChange() so a pick from one page can't be saved for another.
 */
class VariantFilterFields
{
    /**
     * @param  string  $urlField  the sibling field holding the product URL
     */
    public static function make(string $urlField = 'url'): Fieldset
    {
        return Fieldset::make(__('Variant'))
            ->schema([
                TagsInput::make('variant.values')
                    ->label(__('Track only this variant'))
                    ->placeholder(__('eg Small, Black or 55"'))
                    ->helperText(__('Optional. Stock and price come from the matching size, colour, ... instead of the whole page. Every value must match one of the variant\'s options.'))
                    ->splitKeys(['Tab', ','])
                    ->live()
                    // Typed values replace a picked variant, so drop its pinned id.
                    ->afterStateUpdated(fn (Set $set) => $set('variant.id', null))
                    ->hintAction(self::detectAction($urlField))
                    ->columnSpanFull(),

                Select::make('variant_pick')
                    ->label(__('Detected variants'))
                    ->placeholder(__('Pick a variant'))
                    ->options(fn (Get $get): array => collect((array) $get('variant_options'))
                        ->mapWithKeys(fn (array $option, int|string $key): array => [$key => $option['display'] ?? $key])
                        ->all())
                    // Detected variants belong to the page they were detected on.
                    ->visible(fn (Get $get): bool => filled($get('variant_options')) && self::detectedForCurrentUrl($get, $urlField))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, int|string|null $state) use ($urlField): void {
                        $option = data_get((array) $get('variant_options'), (string) $state);

                        if (! is_array($option) || ! self::detectedForCurrentUrl($get, $urlField)) {
                            return;
                        }

                        $set('variant.values', $option['options'] !== [] ? $option['options'] : [$option['label']]);
                        $set('variant.id', $option['id']);
                    })
                    ->columnSpanFull(),

                Hidden::make('variant.id'),

                Hidden::make('variant_options')
                    ->dehydrated(false),

                Hidden::make('variant_options_url')
                    ->dehydrated(false),
            ]);
    }

    /**
     * For the URL field the variant fields belong to: when the URL changes, forget the
     * variants detected on the old page and the pinned id picked from them. The typed
     * values stay, since they can apply to the new page too.
     */
    public static function resetOnUrlChange(): Closure
    {
        return fn (Set $set) => self::clearDetected($set, clearPinnedId: true);
    }

    protected static function clearDetected(Set $set, bool $clearPinnedId = false): void
    {
        $set('variant_options', []);
        $set('variant_options_url', null);
        $set('variant_pick', null);

        if ($clearPinnedId) {
            $set('variant.id', null);
        }
    }

    protected static function detectedForCurrentUrl(Get $get, string $urlField): bool
    {
        $urls = AddUrlsToProductAction::parse((string) $get($urlField));

        return count($urls) === 1 && $urls[0] === $get('variant_options_url');
    }

    /**
     * Scrape the URL and offer its variants to pick from.
     */
    public static function detectAction(string $urlField = 'url'): Action
    {
        return Action::make('detect_variants')
            ->label(__('Detect variants'))
            ->icon(Icons::Search->value)
            ->action(function (Get $get, Set $set) use ($urlField): void {
                // Start from nothing, so a failed detect can't leave the last page's
                // variants to pick from.
                self::clearDetected($set);

                $urls = AddUrlsToProductAction::parse((string) $get($urlField));

                if (count($urls) !== 1) {
                    Notification::make()
                        ->title(count($urls) ? __('Detect variants works with one URL') : __('Enter a product URL first'))
                        ->warning()
                        ->send();

                    return;
                }

                $variants = self::detect($urls[0]);

                if ($variants === null) {
                    Notification::make()
                        ->title(__('Unable to scrape this URL'))
                        ->body(__('Check a store exists for this URL.'))
                        ->danger()
                        ->send();

                    return;
                }

                if ($variants === []) {
                    Notification::make()
                        ->title(__('No variants found'))
                        ->body(__('Type the values instead, or set the store\'s variants strategy.'))
                        ->warning()
                        ->send();

                    return;
                }

                $set('variant_options', $variants);
                $set('variant_options_url', $urls[0]);

                Notification::make()
                    ->title(trans_choice('Found :count variant|Found :count variants', count($variants)))
                    ->success()
                    ->send();
            });
    }

    /**
     * The variants on a page as picker options, or null when the page can't be scraped.
     *
     * @return array<int, array{label: string, options: array<int, string>, id: ?string, display: string}>|null
     */
    public static function detect(string $url): ?array
    {
        try {
            $scrape = ScrapeUrl::new($url)
                ->setSendUiNotifications(false)
                ->scrape(['with_variants' => true]);
        } catch (Throwable) {
            return null;
        }

        if (! data_get($scrape, 'store')) {
            return null;
        }

        return collect(data_get($scrape, ScrapeUrl::VARIANTS_KEY, []))
            ->map(fn ($variant): VariantDto => VariantDto::fromArray((array) $variant))
            ->map(fn (VariantDto $variant): array => [
                'label' => $variant->label,
                'options' => $variant->options,
                'id' => $variant->id,
                'display' => implode(' · ', array_filter([
                    $variant->label ?: $variant->id,
                    $variant->status->getLabel(),
                    $variant->price,
                ])),
            ])
            ->values()
            ->all();
    }
}
