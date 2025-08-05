<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Transformers\StoreTransformer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(StoreResource::API_GROUP)]
class DetailHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = StoreResource::class;

    /**
     * Show Store
     *
     * @return StoreTransformer|JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $query = static::getEloquentQuery();

        $query = QueryBuilder::for(
            $query->where(static::getKeyName(), $id)
        )
            ->first();

        if (! $query) {
            return static::sendNotFoundResponse();
        }

        return new StoreTransformer($query);
    }
}
