<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceResultsTransformer;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceTransformer;
use App\Models\ProductSource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('ProductSource')]
class SearchHandler extends Handlers
{
    public static ?string $uri = '/{id}/search/{query}';

    public static ?string $resource = ProductSourceResource::class;

    /**
     * Search via Product Source
     *
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');
        $searchQuery = $request->route('query');

        $query = static::getEloquentQuery(); //->where('user_id', auth()->id());

        $query = QueryBuilder::for(
            $query->where(static::getKeyName(), $id)
        )
            ->allowedIncludes(['store', 'user'])
            ->first();

        if (! $query) {
            return static::sendNotFoundResponse();
        }

        /** @var ProductSource $query */
        $results = $query->search($searchQuery);

        return ProductSourceResultsTransformer::collection($results);
    }
}
