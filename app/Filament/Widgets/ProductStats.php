<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class ProductStats extends Widget
{
    protected static ?int $sort = 10;

    protected static bool $isLazy = false;

    protected static ?array $cachedProducts = null;

    protected static string $view = 'filament.widgets.product-stats-grouped';

    protected static function getCachedProducts(): array
    {
        return self::$cachedProducts ??= self::getProductsGrouped();
    }

    public static function getProductsGrouped(): array
    {
        return Product::query()
            ->currentUser()
            ->favourite()
            ->published()
            ->with('tags')
            ->orderBy('weight')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Product $product) => isset($product->price_cache[0]))
            ->groupBy(fn (Product $product) => $product->tags->count() > 0
                ? $product->tags->pluck('name')->implode(', ')
                : 'Uncategorized'
            )
            ->map(fn (Collection $products, string $tagName) => [
                'heading' => $tagName,
                'products' => $products,
                'weight' => $products->first()->tags->count() > 0
                    ? $products->first()->tags->max('weight')
                    : 0,
            ])
            ->sortBy('weight')
            ->toArray();
    }

    public function getViewData(): array
    {
        return [
            'groups' => self::getCachedProducts(),
        ];
    }
}
