<?php

namespace App\Filament\Resources\TagResource\Api\Handlers;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use App\Filament\Traits\ApiHelperTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(TagResource::API_GROUP)]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = TagResource::class;

    public function getAllowedFields(): array
    {
        return [
            'id',
            'name',
            'user_id',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedSorts(): array
    {
        return [
            'id',
            'name',
            'created_at',
            'updated_at',
        ];
    }

    public function getAllowedFilters(): array
    {
        return [
            AllowedFilter::exact('name'),
        ];
    }

    public function getAllowedIncludes(): array
    {
        return [
            'products',
        ];
    }

    /**
     * List of Tags
     *
     * @return AnonymousResourceCollection
     */
    public function handler()
    {
        $query = static::getEloquentQuery()->where('user_id', auth()->id());

        // Add search functionality
        if (request()->has('search')) {
            $searchTerm = request()->get('search');
            $query = $query->where('name', 'like', "%{$searchTerm}%");
        }

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
