<?php

namespace App\Models;

use App\Services\Helpers\IntegrationHelper;
use App\Services\SearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $url
 * @property ?float $price
 * @property array $strategies
 * @property ?Store $store
 */
class UrlResearch extends Model
{
    use HasFactory;
    use Prunable;

    public const int DEFAULT_PRUNE_DAYS = 30;

    protected $guarded = [];

    protected $casts = [
        'url' => 'string',
        'price' => 'float',
        'strategies' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Prune search results older than 1 month.
     */
    public function prunable(): Builder
    {
        $settings = IntegrationHelper::getSearchSettings();
        $days = data_get($settings, 'prune_days', self::DEFAULT_PRUNE_DAYS);

        return static::where('created_at', '<=', now()->subDays($days));
    }

    /***************************************************
     * Relationships.
     **************************************************/

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeSearchQuery(Builder $query, string $searchQuery): Builder
    {
        $urls = SearchService::new($searchQuery)
            ->getProductSourceResults()
            ->getRawResults()
            ->getResults()
            ->pluck('url');

        return $query->whereIn('url', $urls);
    }

    public function scopeSetFilters(Builder $query, array $filters): Builder
    {
        $min = $filters['min_price'] ?? 0;
        $max = $filters['max_price'] ?? null;

        if ($min) {
            $query->where('price', '>=', $min);
        }

        if (! empty($max)) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }
}
