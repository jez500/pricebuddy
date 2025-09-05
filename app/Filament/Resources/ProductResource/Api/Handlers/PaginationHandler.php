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

    public function getAllowedFields(): array
    {
        return [
            'id',
            'title',
            'image',
            'status',
            'notify_price',
            'notify_percent',
            'favourite',
            'only_official',
            'weight',
            'current_price',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedSorts(): array
    {
        return [
            'id',
            'title',
            'status',
            'notify_price',
            'favourite',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedFilters(): array
    {
        return [
            'status',
            'favourite',
            'only_official',
        ];
    }

    public function getAllowedIncludes(): array
    {
        return [
            'user',
            'tags',
            'urls',
        ];
    }

    /**
     * List of Products
     *
     * @return AnonymousResourceCollection
     */
    public function handler()
    {
        $query = static::getEloquentQuery()->where('user_id', auth()->id());

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
