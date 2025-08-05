<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Filament\Traits\ApiHelperTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(ProductResource::API_GROUP)]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = ProductResource::class;

    /**
     * List of Products
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

        return ProductTransformer::collection($query);
    }
}
