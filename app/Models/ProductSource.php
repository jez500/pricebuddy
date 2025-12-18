<?php

namespace App\Models;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Enums\ScraperService;
use App\Services\ProductSourceSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property string $name
 * @property string $search_url
 * @property string $scraper_service
 * @property string $type_label
 * @property ?ProductSourceType $type
 * @property ?int $user_id
 * @property string $updated_at
 */
class ProductSource extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'name',
        'slug',
        'search_url',
        'type',
        'store_id',
        'extraction_strategy',
        'settings',
        'status',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductSourceType::class,
            'status' => ProductSourceStatus::class,
            'extraction_strategy' => 'array',
            'settings' => 'array',
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /***************************************************
     * Relationships.
     **************************************************/

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /***************************************************
     * Attributes..
     **************************************************/

    public function getScraperServiceAttribute(): string
    {
        return data_get($this->settings, 'scraper_service', ScraperService::Http->value);
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type?->getLabel() ?? '';
    }

    /***************************************************
     * Scopes.
     **************************************************/

    public function scopeStatus(Builder $query, ProductSourceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->status(ProductSourceStatus::Active);
    }

    /**
     * Scope to only current user.
     */
    public function scopeCurrentUser(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('user_id', auth()->id());
    }

    /***************************************************
     * Helpers.
     **************************************************/

    public function getSearchService(): ProductSourceSearchService
    {
        return ProductSourceSearchService::new($this);
    }

    public function search(string $query): Collection
    {
        return $this->getSearchService()->search($query);
    }

    public function searchDebugData(string $query, int $itemsCount = 5): array
    {
        $service = $this->getSearchService();

        return [
            'html' => $service->getHtml($query),
            'items' => $service->search($query)->take($itemsCount)->toArray(),
            'source' => $this->name,
            'id' => $this->getKey(),
        ];
    }

    public static function userScopedQuery(?User $user = null, int $max = 100, bool $enabledOnly = true): Builder
    {
        /** @var ?User $user */
        $user = $user ?? auth()->user();

        $query = static::query()
            ->orderBy('weight')
            ->take($max)
            ->when($enabledOnly, fn ($q) => $q->enabled());

        if ($user) {
            $query = $query->where('user_id', $user->getKey());
        }

        return $query;
    }
}
