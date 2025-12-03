<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Transformers;

use App\Http\Resources\UserResource;
use App\Models\ProductSource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ProductSource $resource
 */
class ProductSourceTransformer extends JsonResource
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
        if ($this->resource->relationLoaded('store')) {
            $data['store'] = $this->resource->store?->toArray();
        }

        if ($this->resource->relationLoaded('user')) {
            $data['user'] = new UserResource($this->resource->user);
        }

        return $data;
    }
}
