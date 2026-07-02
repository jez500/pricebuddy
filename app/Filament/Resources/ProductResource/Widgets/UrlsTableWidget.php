<?php

namespace App\Filament\Resources\ProductResource\Widgets;

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
                            ->rules([new StoreUrl]),
                        TextInput::make('price_factor')
                            ->label('Price Factor')
                            ->numeric()
                            ->default(1)
                            ->minValue(0.01)
                            ->required(),
                    ])
                    ->using(function (Url $record, array $data): Url {
                        // Validate/apply the URL change first so a failed change leaves
                        // nothing persisted (changeUrl fails closed) before price_factor.
                        if (trim($data['url']) !== $record->url && ! $record->changeUrl($data['url'])) {
                            throw ValidationException::withMessages([
                                'url' => __('Unable to resolve a store or price for this URL'),
                            ]);
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
}
