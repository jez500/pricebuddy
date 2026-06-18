<?php

namespace App\Services\Insights;

use App\Dto\Insights\ListingHistory;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\Url;
use Illuminate\Support\Collection;

class ListingHistoriesBuilder
{
    /**
     * @return Collection<int, ListingHistory>
     */
    public function build(Product $product): Collection
    {
        $urls = $product->urls()->with('store')->get()->keyBy('id');

        return $product->getPriceHistory()
            ->map(function (Collection $history, int $urlId) use ($urls): ListingHistory {
                /** @var ?Url $url */
                $url = $urls->get($urlId);

                $store = $url?->store;

                return new ListingHistory(
                    storeName: $store?->name ?? 'Unknown', // @phpstan-ignore nullsafe.neverNull
                    history: $history,
                    availability: $url?->availability ?? StockStatus::InStock, // @phpstan-ignore nullsafe.neverNull
                    urlId: $urlId,
                );
            })
            ->values();
    }
}
