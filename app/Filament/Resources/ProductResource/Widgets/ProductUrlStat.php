<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Dto\PriceCacheDto;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;

class ProductUrlStat extends Stat
{
    public ?PriceCacheDto $priceCache = null;

    public ?Product $product = null;

    public int $idx = 0;

    protected int $priceHistoryLength = 182; // 6 mths.

    public function render(): View
    {
        return view('filament.pages.product.price-stat', $this->data());
    }

    public function setPriceCache(int $idx, PriceCacheDto $cache, ?Product $product = null): self
    {
        $this->idx = $idx;
        $this->priceCache = $cache;
        $this->product = $product;

        parent::description($cache->getTrendText());
        parent::descriptionIcon($cache->getTrendIcon());
        parent::color($cache->getTrendColor());
        parent::url($cache->getUrl(), true);

        parent::chart(
            $cache->getHistory()
                ->values()->reverse()->take($this->priceHistoryLength)
                ->reverse()->values()->toArray()
        );

        return $this;
    }

    public function data()
    {
        return array_merge(parent::data(), [
            'priceCache' => $this->priceCache,
            'idx' => $this->idx,
            'product' => $this->product,
        ]);
    }
}
