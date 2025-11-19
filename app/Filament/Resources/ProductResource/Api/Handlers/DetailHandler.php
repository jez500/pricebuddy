<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use Dedoc\Scramble\Attributes\Group;
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
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $query = static::getEloquentQuery()->where('user_id', auth()->id());

        $query = QueryBuilder::for(
            $query->where(static::getKeyName(), $id)
        )
            ->allowedIncludes(['tags', 'user'])
            ->first();

        if (! $query) {
            return static::sendNotFoundResponse();
        }

        return new ProductTransformer($query);
    }
}
