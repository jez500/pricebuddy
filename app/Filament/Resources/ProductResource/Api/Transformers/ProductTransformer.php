<?php

namespace App\Filament\Resources\ProductResource\Api\Transformers;

use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use App\Http\Resources\UserResource;
use App\Models\Product;
use App\Services\Insights\ProductInsights;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Product $resource
 */
class ProductTransformer extends JsonResource
{
    protected bool $withInsights = false;

    /**
     * Opt into embedding the materialized insights payload.
     */
    public function withInsights(bool $value = true): static
    {
        $this->withInsights = $value;

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

        if ($this->withInsights) {
            $data['insights'] = is_array($this->resource->insights_cache) && $this->resource->insights_cache !== []
                ? $this->resource->insights_cache
                : ProductInsights::for($this->resource)->toArray();
        }

        return $data;
    }
}
