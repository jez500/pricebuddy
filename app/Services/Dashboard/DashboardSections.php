<?php

namespace App\Services\Dashboard;

use App\Enums\StockStatus;
use App\Enums\Trend;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

class DashboardSections
{
    private const BUY_NOW_MIN_SCORE = 6.0;

    /** @var ?Collection<int, Product> */
    private ?Collection $trackedProducts = null;

    public function __construct(private readonly User $user) {}

    /**
     * Products belonging to the user that are published and have a usable
     * materialized price cache (matches the widget's `price_cache[0]` guard).
     * Memoized: repeated calls within one instance reuse the same query.
     *
     * @return Collection<int, Product>
     */
    private function trackedProducts(): Collection
    {
        return $this->trackedProducts ??= Product::query()
            ->where('user_id', $this->user->id)
            ->published()
            ->get()
            ->filter(fn (Product $p): bool => $this->isTracked($p))
            ->values();
    }

    /**
     * Whether a product has a usable materialized price cache (matches the
     * widget's `price_cache[0]` guard). Shared by `trackedProducts()` and
     * `needsAttention()` so the "excluded from all sections" invariant lives
     * in one place.
     */
    private function isTracked(Product $p): bool
    {
        return $p->current_price > 0 && ! empty($p->price_cache);
    }

    /**
     * @return array{tracked:int, atLowest:int, belowAverage:int, outOfStock:int, potentialSavings:float}
     */
    public function statBar(): array
    {
        $products = $this->trackedProducts();

        return [
            'tracked' => $products->count(),
            'atLowest' => $products->filter(fn (Product $p): bool => $p->trend === Trend::Lowest->value)->count(),
            'belowAverage' => $products->filter(fn (Product $p): bool => in_array($p->trend, [Trend::Down->value, Trend::Lowest->value], true))->count(),
            'outOfStock' => $products->filter(fn (Product $p): bool => $this->isOutOfStock($p))->count(),
            'potentialSavings' => round($products->sum(fn (Product $p): float => max(0, $p->getPriceCacheAggregate('avg') - $p->current_price)), 2),
        ];
    }

    /**
     * @return Collection<int, Product>
     */
    public function buyNow(): Collection
    {
        return $this->trackedProducts()
            ->filter(fn (Product $p): bool => (float) data_get($p->insights_cache, 'dealScore.score', 0) >= self::BUY_NOW_MIN_SCORE)
            ->sortByDesc(fn (Product $p): array => [
                (bool) data_get($p->insights_cache, 'dealScore.isAllTimeLow', false) ? 1 : 0,
                (float) data_get($p->insights_cache, 'dealScore.score', 0),
            ])
            ->values();
    }

    /**
     * @return Collection<int, Product>
     */
    public function recentlyDropped(int $days = 7): Collection
    {
        $droppedIds = Product::query()
            ->where('user_id', $this->user->id)
            ->published()
            ->lowestPriceInDays($days)
            ->pluck('id');

        return $this->trackedProducts()
            ->filter(fn (Product $p): bool => $droppedIds->contains($p->id))
            ->sortByDesc(fn (Product $p): float => $p->getPriceCacheAggregate('avg') - $p->current_price)
            ->values();
    }

    /**
     * Non-paused, published products with a failed/stale last scrape. The
     * `favourite` filter is intentionally relaxed here: a non-favourite
     * product that is failing to scrape must still surface so the user
     * notices it.
     *
     * @return Collection<int, Product>
     */
    public function needsAttention(): Collection
    {
        return Product::query()
            ->where('user_id', $this->user->id)
            ->published()
            ->where('paused', false)
            ->get()
            ->filter(fn (Product $p): bool => $this->isTracked($p) && ! $p->is_last_scrape_successful)
            ->values();
    }

    /**
     * A product is considered out of stock when any cached price row's
     * `availability` matches `StockStatus::OutOfStock` (see
     * `PriceCacheDto::toArray()`, which stores `null` for in-stock rows and
     * the enum's string value otherwise).
     */
    private function isOutOfStock(Product $p): bool
    {
        return collect($p->price_cache)
            ->contains(fn ($row): bool => ($row['availability'] ?? null) === StockStatus::OutOfStock->value);
    }
}
