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
                'signature' => self::categorySignature($products->first()->tags),
                'products' => $products,
                'weight' => $products->first()->tags->count() > 0
                    ? $products->first()->tags->max('weight')
                    : 0,
            ])
            ->sortBy('weight')
            ->toArray();
    }

    public static function categorySignature(\Illuminate\Support\Collection $tags): string
    {
        return $tags->isEmpty()
            ? 'uncategorized'
            : $tags->pluck('id')->sort()->values()->implode('-');
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $layout = new \App\Services\Dashboard\DashboardLayoutService($user);
        $sectionsService = new \App\Services\Dashboard\DashboardSections($user);

        $groups = $this->orderGroups(self::getProductsGrouped(), $layout);

        return [
            'groups' => $groups,
            'sections' => $layout->sections(),
            'sectionData' => [
                'stat_bar' => $sectionsService->statBar(),
                'buy_now' => $sectionsService->buyNow(),
                'recently_dropped' => $sectionsService->recentlyDropped(),
                'needs_attention' => $sectionsService->needsAttention(),
            ],
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function orderGroups(array $groups, \App\Services\Dashboard\DashboardLayoutService $layout): array
    {
        $order = $layout->categoryOrder();

        $groups = collect($groups)->map(function (array $group) use ($layout): array {
            $group['collapsed'] = $layout->isCategoryCollapsed($group['signature']);

            return $group;
        });

        if ($order === []) {
            return $groups->values()->all(); // already weight-sorted by getProductsGrouped()
        }

        $rank = array_flip($order); // signature => index

        return $groups
            ->sortBy(fn (array $group): int => $rank[$group['signature']] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }
}
