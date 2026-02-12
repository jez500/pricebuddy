<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Dto\PriceCacheDto;
use App\Models\Product;
use App\Models\Url;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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
