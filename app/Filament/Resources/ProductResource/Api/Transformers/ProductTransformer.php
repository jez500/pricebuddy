<?php

namespace App\Filament\Resources\ProductResource\Api\Transformers;

use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use App\Http\Resources\UserResource;
use App\Models\Product;
use App\Models\Url;
use App\Services\Insights\ProductInsights;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Product $resource
 */
class ProductTransformer extends JsonResource
{
    protected bool $withInsights = false;

    protected ?string $currentUrl = null;

    /** @var array<int, int>|null */
    protected ?array $currentUrlIds = null;

    /**
     * Longest `current_url` we will normalise. Anything longer flags nothing rather
     * than erroring, since the extension passes through whatever location.href gives it.
     */
    public const int MAX_CURRENT_URL_LENGTH = 2048;

    /**
     * Opt into embedding the materialized insights payload.
     */
    public function withInsights(bool $value = true): static
    {
        $this->withInsights = $value;

        return $this;
    }

    /**
     * Opt into decorating each price_cache entry with `is_current`.
     *
     * Passing `$matchingUrlIds` skips the per-product lookup, which is how the list
     * endpoint resolves the whole page in one query instead of N+1. Entries only ever
     * reference their own product's url ids, so sharing one page-wide set is safe.
     *
     * @param  array<int, int>|null  $matchingUrlIds
     */
    public function withCurrentUrl(?string $currentUrl, ?array $matchingUrlIds = null): static
    {
        $this->currentUrl = $currentUrl;
        $this->currentUrlIds = $matchingUrlIds;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = $this->resource->toArray();

        // Include relationships if they are loaded
        if ($this->resource->relationLoaded('tags')) {
            $data['tags'] = TagTransformer::collection($this->resource->tags->makeHidden('pivot'));
        }

        if ($this->resource->relationLoaded('user')) {
            $data['user'] = new UserResource($this->resource->user);
        }

        if ($this->currentUrl !== null) {
            $data['price_cache'] = $this->decoratePriceCache(is_array($data['price_cache'] ?? null) ? $data['price_cache'] : []);
        }

        if ($this->withInsights) {
            $data['insights'] = ProductInsights::for($this->resource)->toArray();
        }

        return $data;
    }

    /**
     * Add `is_current` to every price cache entry. Operates on the serialised array
     * only — the model's `price_cache` attribute is untouched, so this can never be
     * written back into the materialised JSON column.
     *
     * @param  array<int, array<string, mixed>>  $priceCache
     * @return array<int, array<string, mixed>>
     */
    protected function decoratePriceCache(array $priceCache): array
    {
        $matching = $this->matchingUrlIds();

        return array_map(
            fn (array $entry): array => array_merge($entry, [
                'is_current' => in_array((int) ($entry['url_id'] ?? 0), $matching, true),
            ]),
            $priceCache
        );
    }

    /**
     * The url ids on this product that the current page resolves to.
     *
     * Matching keys off `url_id` rather than normalising `price_cache[].url`, because
     * that field is `buy_url` — the affiliate-tagged form. eBay's six affiliate params
     * are not on the tracking denylist, so normalising it would make `is_current` false
     * for every eBay listing.
     *
     * @return array<int, int>
     */
    protected function matchingUrlIds(): array
    {
        if (is_array($this->currentUrlIds)) {
            return $this->currentUrlIds;
        }

        $currentUrl = (string) $this->currentUrl;

        if (strlen($currentUrl) > self::MAX_CURRENT_URL_LENGTH) {
            return [];
        }

        $normalized = Url::normalizeForMatch($currentUrl);

        if ($normalized === '') {
            return [];
        }

        return $this->resource->urls()
            ->where('url_normalized', $normalized)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
