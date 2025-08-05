<?php

namespace App\Filament\Resources\TagResource\Api\Handlers;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(TagResource::API_GROUP)]
class DetailHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = TagResource::class;

    /**
     * Show Tag
     *
     * @return TagTransformer|JsonResponse
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

        return new TagTransformer($query);
    }
}
