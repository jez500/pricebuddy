<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceTransformer;
use App\Filament\Traits\ApiHelperTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('ProductSource')]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = ProductSourceResource::class;

    public function getAllowedFields(): array
    {
        return [
            'id',
            'name',
            'slug',
            'search_url',
            'type',
            'store_id',
            'status',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedSorts(): array
    {
        return [
            'id',
            'name',
            'slug',
            'type',
            'status',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedFilters(): array
    {
        return [
            'type',
            'status',
            'store_id',
        ];
    }

    public function getAllowedIncludes(): array
    {
        return [
            'store',
            'user',
        ];
    }

    /**
     * List of Product Sources
     *
     * @return AnonymousResourceCollection
     */
    public function handler()
    {
        $query = static::getEloquentQuery()->where('user_id', auth()->id());
        $perPage = min(max((int) $this->getPerPage(), 1), 100);

        $query = QueryBuilder::for($query)
            ->allowedFields($this->getAllowedFields())
            ->allowedSorts($this->getAllowedSorts())
            ->allowedFilters($this->getAllowedFilters())
            ->allowedIncludes($this->getAllowedIncludes())
            ->paginate($perPage)
            ->appends(request()->query());

        return ProductSourceTransformer::collection($query);
    }
}
