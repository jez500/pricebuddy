<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceTransformer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('ProductSource')]
class DetailHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = ProductSourceResource::class;

    /**
     * Show Product Source
     *
     * @return ProductSourceTransformer|JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $query = static::getEloquentQuery()->where('user_id', auth()->id());

        $query = QueryBuilder::for(
            $query->where(static::getKeyName(), $id)
        )
            ->allowedIncludes(['store', 'user'])
            ->first();

        if (! $query) {
            return static::sendNotFoundResponse();
        }

        return new ProductSourceTransformer($query);
    }
}
