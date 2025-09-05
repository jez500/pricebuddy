<?php

namespace App\Filament\Resources\ProductResource\Api\Transformers;

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
            $data['tags'] = $this->resource->tags->toArray();
        }

        if ($this->resource->relationLoaded('user')) {
            $data['user'] = $this->resource->user->toArray();
        }

        return $data;
    }
}
