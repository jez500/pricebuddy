<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Filament\Traits\ApiHelperTrait;
use App\Models\Url;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(ProductResource::API_GROUP)]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = ProductResource::class;

    public function getAllowedFields(): array
    {
        return [
            'id',
            'title',
            'image',
            'status',
            'notify_price',
            'notify_percent',
            'favourite',
            'only_official',
            'weight',
            'current_price',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedSorts(): array
    {
        return [
            'id',
            'title',
            'status',
            'notify_price',
            'favourite',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedFilters(): array
    {
        return [
            'status',
            'favourite',
            'only_official',
            AllowedFilter::callback('url', function (Builder $query, $value): void {
                $normalized = Url::normalizeForMatch((string) $value);

                // An unparseable URL must match nothing rather than error. Short-circuit
                // without touching the join; stored rows hold NULL for unparseable URLs
                // so they could never match '' anyway.
                if ($normalized === '') {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereHas('urls', fn (Builder $urlQuery) => $urlQuery->where('url_normalized', $normalized));
            }),
        ];
    }

    public function getAllowedIncludes(): array
    {
        return [
            'user',
            'tags',
            'urls',
        ];
    }

    /**
     * List of Products
     *
     * @return AnonymousResourceCollection
     */
    #[QueryParameter('filter[url]', description: 'Return products tracking this page URL. Matching is normalised: scheme, port, "www.", trailing slashes, casing and tracking parameters are ignored.', type: 'string')]
    #[QueryParameter('current_url', description: 'Raw URL of the page being viewed. When supplied, each price_cache entry gains an `is_current` boolean.', type: 'string')]
    public function handler(Request $request)
    {
        $query = static::getEloquentQuery()->where('user_id', auth()->id());
        $perPage = min(max((int) $this->getPerPage(), 1), 100);

        // 'insights' is a detail-only pseudo-include; strip it so the list silently
        // ignores it rather than Spatie rejecting an unknown include.
        $includes = array_filter(explode(',', (string) $request->query('include', '')));
        $apiRequest = $request->duplicate(
            array_merge($request->query->all(), ['include' => implode(',', array_diff($includes, ['insights']))])
        );

        $query = QueryBuilder::for($query, $apiRequest)
            ->allowedFields($this->getAllowedFields())
            ->allowedSorts($this->getAllowedSorts())
            ->allowedFilters($this->getAllowedFilters())
            ->allowedIncludes($this->getAllowedIncludes())
            ->paginate($perPage)
            ->appends(request()->query());

        $transformed = ProductTransformer::collection($query);

        $currentUrl = $request->query('current_url');

        if (is_string($currentUrl)) {
            $matchingUrlIds = $this->resolveMatchingUrlIds($currentUrl, $query->getCollection()->pluck('id')->all());

            $transformed->collection->each(
                fn (ProductTransformer $transformer) => $transformer->withCurrentUrl($currentUrl, $matchingUrlIds)
            );
        }

        return $transformed;
    }

    /**
     * Resolve the url ids matching the current page across a whole page of products,
     * in one query rather than one per product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    protected function resolveMatchingUrlIds(string $currentUrl, array $productIds): array
    {
        if ($productIds === [] || strlen($currentUrl) > ProductTransformer::MAX_CURRENT_URL_LENGTH) {
            return [];
        }

        $normalized = Url::normalizeForMatch($currentUrl);

        if ($normalized === '') {
            return [];
        }

        return Url::query()
            ->where('url_normalized', $normalized)
            ->whereIn('product_id', $productIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
