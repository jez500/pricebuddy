<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Transformers\StoreTransformer;
use App\Filament\Traits\ApiHelperTrait;
use App\Models\Store;
use App\Models\Url;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
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
            AllowedFilter::callback('domain', function (Builder $query, $value): void {
                // Spatie splits filter values on its array delimiter (default ','),
                // so a value containing a comma arrives here as an array — nested
                // again if sent as filter[domain][]=.
                $host = Url::normalizeHost($this->filterValueToString($value));

                if ($host === '') {
                    $query->whereRaw('0 = 1');

                    return;
                }

                // MySQL JSON columns collate utf8mb4_bin, so neither whereJsonContains
                // nor a plain LIKE can compare case-insensitively. Narrow in SQL, then
                // compare exactly in PHP. The LIKE is a sound superset: normalizeHost
                // only lowercases and strips a leading "www.", so the normalised host is
                // always a substring of the lowercased stored value.
                //
                // Note: the tenancy boundary is the outer query scope already applied in
                // handler() (->where('user_id', auth()->id())), which this filter's
                // whereIn() composes onto with AND. The user_id clause below is NOT the
                // tenancy boundary — it exists to bound how many rows are hydrated for the
                // PHP comparison (without it, every user's matching stores would be loaded
                // into memory here), plus defence in depth.
                $ids = Store::query()
                    ->where('user_id', auth()->id())
                    ->whereRaw('LOWER(domains) LIKE ?', ['%'.addcslashes($host, '%_').'%'])
                    ->get(['id', 'domains'])
                    ->filter(fn (Store $store): bool => collect($store->domains)
                        ->contains(fn ($entry): bool => Url::normalizeHost((string) data_get($entry, 'domain')) === $host))
                    ->pluck('id')
                    ->all();

                $query->whereIn('id', $ids);
            })
                // Laravel's ConvertEmptyStringsToNull middleware turns filter[domain]=
                // into a null value before Spatie sees it. AllowedFilter::filter() skips
                // invoking the callback for null values unless the filter is nullable, so
                // without this the empty-string/garbage case would silently fall through
                // to an unfiltered query instead of reaching the "0 = 1" guard above.
                ->nullable(),
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
     * The sparse fieldset requested for stores, if any. Spatie accepts the comma-separated
     * form (fields[stores]=id,name) and clients sometimes send the array form, so both are
     * flattened to a plain list. An empty list means no sparse fieldset was requested.
     *
     * @return array<int, string>
     */
    protected function requestedStoreFields(): array
    {
        return collect(Arr::wrap(request()->input('fields.stores')))
            ->flatMap(fn ($value): array => explode(',', (string) $value))
            ->map(fn (string $field): string => trim($field))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * List of Stores
     *
     * @return AnonymousResourceCollection
     */
    #[QueryParameter('filter[domain]', description: 'Exact-match filter on a bare host such as "www.Target.com.au". Case and a leading "www." are ignored. Unlike filter[domains], this does not partial match.', type: 'string')]
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
            ->allowedIncludes($this->getAllowedIncludes());

        // StoreTransformer exposes `currency` and `locale`, which are accessors reading from
        // `settings`. They are deliberately absent from getAllowedFields() (selecting a
        // non-existent column would blow up), but a sparse fieldset that omits `settings`
        // would leave the accessors reading a missing attribute and silently reporting the
        // app-level fallback instead of the store's override. Force the column into the
        // select in that case. Only when fields were requested: addSelect() on a query with
        // no select clause would narrow `select *` down to this one column.
        $requestedFields = $this->requestedStoreFields();

        if ($requestedFields !== []) {
            $query->addSelect('stores.settings');
        }

        $query = $query
            ->paginate($this->getPerPage())
            ->appends(request()->query());

        // `settings` was selected to resolve those accessors, not because the client asked
        // for it. Hide it again so a sparse fieldset returns only the requested fields —
        // it carries per-store scraper configuration and has no business leaking into a
        // response that asked for `id,name`.
        if ($requestedFields !== [] && ! in_array('settings', $requestedFields, true)) {
            $query->getCollection()->each(fn (Model $store) => $store->makeHidden('settings'));
        }

        return StoreTransformer::collection($query);
    }
}
