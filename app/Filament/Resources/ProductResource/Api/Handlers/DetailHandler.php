<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(ProductResource::API_GROUP)]
class DetailHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = ProductResource::class;

    /**
     * Show Product
     *
     * @return ProductTransformer|JsonResponse
     */
    #[QueryParameter('current_url', description: 'Raw URL of the page being viewed. When supplied, each price_cache entry gains an `is_current` boolean, true for the listing matching this page.', type: 'string')]
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $includes = array_filter(explode(',', (string) $request->query('include', '')));
        $wantsInsights = in_array('insights', $includes, true);

        // 'insights' is a computed block, not an Eloquent relation. Strip it from
        // the include list passed to Spatie so it does not reject an unknown include.
        $apiRequest = $request->duplicate(
            array_merge($request->query->all(), ['include' => implode(',', array_diff($includes, ['insights']))])
        );

        $query = static::getEloquentQuery()->where('user_id', auth()->id());

        $query = QueryBuilder::for(
            $query->where(static::getKeyName(), $id),
            $apiRequest
        )
            ->allowedIncludes(['tags', 'user'])
            ->first();

        if (! $query) {
            return static::sendNotFoundResponse();
        }

        $currentUrl = $request->query('current_url');

        return (new ProductTransformer($query))
            ->withInsights($wantsInsights)
            ->withCurrentUrl(is_string($currentUrl) ? $currentUrl : null);
    }
}
