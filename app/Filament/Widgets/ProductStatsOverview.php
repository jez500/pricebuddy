<?php

namespace App\Filament\Widgets;

use App\Dto\PriceCacheDto;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

class ProductStatsOverview extends BaseWidget
{
    protected static ?int $sort = 10;

    public ?array $ids = null;

    public function mount(?array $ids = null)
    {
        $this->ids = $ids;
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $products = Product::latest();

        if (! is_null($this->ids)) {
            $products->whereIn('id', $this->ids);
        }

        return $products
            ->currentUser()
            ->published()
            ->favourite()
            ->with('tags')
            ->get()
            ->filter(fn (Product $product) => isset($product->price_cache[0]))
            ->map(function (Product $product) {
                /** @var PriceCacheDto $lowest */
                $lowest = $product->getPriceCache()->first();

                return Stat::make(Str::limit($product->title, 40), $lowest->getPriceFormatted())
                    ->description($lowest->getTrendText())
                    ->descriptionIcon($lowest->getTrendIcon())
                    ->chart(
                        $lowest->getHistory(10)->values()->toArray()
                    )
                    ->color($lowest->getTrendColor())
                    ->url($product->action_urls['view']);
            })
            ->values()
            ->toArray();
    }
}
