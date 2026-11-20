<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Dto\VariantFilterDto;
use App\Filament\Forms\VariantFilterFields;
use App\Models\Product;
use App\Models\Url;
use App\Rules\StoreUrl;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UrlsTableWidget extends BaseWidget
{
    public Model|Product|null $record = null;

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->record;

        return $table
            ->heading('Product Urls')
            ->query(
                $product->urls()->with('store')->getQuery()
            )
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('store.name')
                            ->label('Store'),
                        Tables\Columns\TextColumn::make('url')
                            ->label('Url')
                            ->color('gray')
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<a href="'.$state.'" title="'.$state.'" target="_blank">'.Str::limit($state, 80).'</a>')
                            ),
                    ]),
                    Tables\Columns\TextColumn::make('variant_label')
                        ->label('Variant')
                        ->getStateUsing(fn (Url $record): ?string => $record->variantLabel())
                        ->grow(false)
                        ->badge()
                        ->icon('heroicon-m-tag')
                        ->color('gray'),
                    Tables\Columns\TextColumn::make('price_factor')
                        ->label('Price Factor')
                        ->grow(false)
                        ->badge()
                        ->color('gray'),
                ]),

            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        TextInput::make('url')
                            ->label('URL')
                            ->required()
                            ->rules([new StoreUrl])
                            ->live(onBlur: true)
                            ->afterStateUpdated(VariantFilterFields::resetOnUrlChange()),
                        TextInput::make('price_factor')
                            ->label('Price Factor')
                            ->numeric()
                            ->default(1)
                            ->minValue(0.01)
                            ->required(),
                        VariantFilterFields::make(),
                    ])
                    ->using(function (Url $record, array $data): Url {
                        $variant = $data['variant'] ?? null;

                        // Validate/apply the URL change first so a failed change leaves
                        // nothing persisted (changeUrl fails closed) before price_factor.
                        // A new variant is applied with it, so the new page is scraped for
                        // it, and its stock is a fresh baseline rather than a stock change.
                        if (trim($data['url']) !== $record->url) {
                            $variantChanged = $record->variantDiffers($variant);
                            $record->variant = VariantFilterDto::fromArray($variant)?->toArray();

                            if (! $record->changeUrl($data['url'], silentStockChange: $variantChanged)) {
                                $record->refresh();

                                throw ValidationException::withMessages([
                                    'url' => __('Unable to resolve a store or price for this URL'),
                                ]);
                            }
                        } elseif (! $record->changeVariant($variant)) {
                            self::notifyVariantNotFound();
                        }

                        $record->price_factor = (float) $data['price_factor'];
                        $record->save();

                        return $record;
                    })
                    ->after(function (Url $record) {
                        $record->syncStoredPricesForCurrentFactor();
                        $record->product->updatePriceCache();

                        Notification::make('url_updated')
                            ->title('URL updated')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function notifyVariantNotFound(): void
    {
        Notification::make('variant_not_found')
            ->title(__('Variant not changed'))
            ->body(__('Its stock could not be found on the page. Check the variant values, or the store\'s variants strategy.'))
            ->warning()
            ->send();
    }
}
