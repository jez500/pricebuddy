<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Transformers\StoreTransformer;
use App\Filament\Traits\ApiHelperTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Rupadana\ApiService\Http\Handlers;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(StoreResource::API_GROUP)]
class PaginationHandler extends Handlers
{
    use ApiHelperTrait;

    public static ?string $uri = '/';

    public static ?string $resource = StoreResource::class;

    public function getAllowedFields(): array
    {
        return [
            'id',
            'name',
            'initials',
            'domains',
            'scrape_strategy',
            'settings',
            'notes',
            'slug',
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
            AllowedFilter::partial('domains'),
            AllowedFilter::exact('scraper_service', 'settings->scraper_service'),
        ];
    }

    public function getAllowedIncludes(): array
    {
        return [
            'user',
            'urls',
            'products',
        ];
    }

    /**
     * List of Stores
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

        return StoreTransformer::collection($query);
    }
}
