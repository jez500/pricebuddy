<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Dto\PriceCacheDto;
use App\Models\Product;
use App\Models\Url;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;

class ProductUrlStats extends BaseWidget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?int $sort = 10;

    public Model|Product|null $record = null;

    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        /** @var Product $product */
        $product = $this->record;

        $products = $product->getPriceCache()
            ->map(function (PriceCacheDto $cache, $idx) use ($product) {
                return ProductUrlStat::make(
                    '@ '.$cache->getStoreName().($idx === 0 ? ' (Lowest price)' : ''),
                    $cache->getUnitPriceFormatted()
                )->setPriceCache($idx, $cache, $product);
            })->values();

        return $products->toArray();
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->size('sm')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->outlined(false)
            ->requiresConfirmation(true)
            ->action(function ($arguments) {
                $url = Url::find($arguments['url']);
                $backUrl = $url?->product?->view_url;
                $url?->delete();

                Notification::make('deleted_url')
                    ->title('URL deleted')
                    ->success()
                    ->send();

                if ($backUrl) {
                    return redirect($backUrl);
                }
            });
    }

    public function fetchAction(): Action
    {
        return Action::make('fetch')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-rocket-launch')
            ->outlined(false)
            ->action(function ($arguments) {
                try {
                    $url = Url::find($arguments['url']);
                    $backUrl = $url->product?->view_url;
                    $url->updatePrice();

                    Notification::make('deleted_url')
                        ->title('Prices updated')
                        ->success()->send();

                    if ($backUrl) {
                        return redirect($backUrl);
                    }
                } catch (Exception $e) {
                    Notification::make('deleted_url_failed')
                        ->title('Couldn\'t fetch the product, refer to logs')
                        ->danger()->send();
                }

            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-pencil-square')
            ->outlined(false)
            ->fillForm(function ($arguments) {
                $url = Url::find($arguments['url']);

                return [
                    'url' => $url->url,
                    'price_factor' => $url->price_factor,
                ];
            })
            ->form([
                Placeholder::make('url')
                    ->label('URL')
                    ->content(fn ($get) => new \Illuminate\Support\HtmlString(
                        '<span style="cursor:pointer" x-on:click="const range = document.createRange(); range.selectNodeContents($el); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range)">'.e($get('url')).'</span>'
                    )),
                TextInput::make('price_factor')
                    ->label('Price Factor')
                    ->numeric()
                    ->default(1)
                    ->minValue(0.01)
                    ->required(),
            ])
            ->action(function ($arguments, $data) {
                $url = Url::find($arguments['url']);
                $url->update(['price_factor' => $data['price_factor']]);
                $url->updatePrice();
                $url->product->updatePriceCache();

                Notification::make('price_factor_updated')
                    ->title('Price factor updated')
                    ->success()
                    ->send();

                $backUrl = $url->product?->view_url;
                if ($backUrl) {
                    return redirect($backUrl);
                }
            });
    }

    public function viewAction(): Action
    {
        return Action::make('buy')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-shopping-bag')
            ->outlined(false)
            ->url(fn ($arguments) => $arguments['url']);
    }
}
