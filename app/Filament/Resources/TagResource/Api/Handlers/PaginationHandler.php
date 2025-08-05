<?php

namespace App\Filament\Resources\TagResource\Api\Handlers;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use App\Filament\Traits\ApiHelperTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(TagResource::API_GROUP)]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = TagResource::class;

    /**
     * List of Tags
     *
     * @return AnonymousResourceCollection
     */
    public function handler()
    {
        $query = static::getEloquentQuery();

        $query = QueryBuilder::for($query)
            ->allowedFields($this->getAllowedFields())
            ->allowedSorts($this->getAllowedSorts())
            ->allowedFilters($this->getAllowedFilters())
            ->allowedIncludes($this->getAllowedIncludes())
            ->paginate($this->getPerPage())
            ->appends(request()->query());

        return TagTransformer::collection($query);
    }
}
