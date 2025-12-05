<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceResultsTransformer;
use App\Models\ProductSource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('ProductSource')]
class SearchAllHandler extends Handlers
{
    public static ?string $uri = '/search/{query}';

    public static ?string $resource = ProductSourceResource::class;

    /**
     * Search all Product Sources
     *
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function handler(Request $request)
    {

        $searchQuery = $request->route('query');

        $query = static::getEloquentQuery()->enabled();

        $query = QueryBuilder::for(
            $query
        )
            ->allowedIncludes(['store', 'user'])
            ->take(100)
            ->get();

        $results = collect();
        $query->each(function ($source) use (&$results, $searchQuery) {
            /** @var ProductSource $source */
            $sourceResults = $source->search($searchQuery)
                ->map(fn ($result) => array_merge($result, ['source' => $source->name, 'source_id' => $source->getKey()]));
            $results->push($sourceResults);
        });

        $results = $results->flatten(1)->values();

        return ProductSourceResultsTransformer::collection($results);
    }
}
