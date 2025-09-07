<?php

namespace App\Filament\Resources\ProductResource\Api\Transformers;

use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use App\Http\Resources\UserResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Product $resource
 */
class ProductTransformer extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
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

        return $data;
    }
}
