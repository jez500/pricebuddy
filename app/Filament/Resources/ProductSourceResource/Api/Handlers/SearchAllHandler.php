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
use Throwable;

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

        $query = QueryBuilder::for(
            ProductSource::userScopedQuery()
        )
            ->allowedIncludes(['store', 'user'])
            ->get();

        $results = collect();
        $query->each(function ($source) use (&$results, $searchQuery) {
            try {
                /** @var ProductSource $source */
                $sourceResults = $source->search($searchQuery)
                    ->map(fn ($result) => array_merge($result, ['source' => $source->name, 'source_id' => $source->getKey()]));
                $results->push($sourceResults);
            } catch (Throwable $e) {
                logger()->error('Error getting source results: '.$e->getMessage(), [
                    'source' => $source->name,
                    'source_id' => $source->getKey(),
                ]);
            }

        });

        $results = $results->flatten(1)->values();

        return ProductSourceResultsTransformer::collection($results);
    }
}
