<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use App\Models\Url;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                        \Filament\Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->disabled(),
                        \Filament\Forms\Components\TextInput::make('price_factor')
                            ->label('Price Factor')
                            ->numeric()
                            ->default(1)
                            ->minValue(0.01)
                            ->required(),
                    ])
                    ->after(function (Url $record) {
                        $record->syncStoredPricesForCurrentFactor();
                        $record->product->updatePriceCache();

                        Notification::make('price_factor_updated')
                            ->title('Price factor updated')
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
