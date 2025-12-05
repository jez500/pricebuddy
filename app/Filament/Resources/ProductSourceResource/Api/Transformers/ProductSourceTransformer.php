<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Transformers;

use App\Http\Resources\StoreResource;
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
        $data = [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'type' => $this->resource->type,
            'status' => $this->resource->status,
            'user_id' => $this->resource->user_id,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];

        // Include relationships if they are loaded
        if ($this->resource->relationLoaded('store') && $this->resource->store !== null) {
            $data['store'] = new StoreResource($this->resource->store);
        }

        if ($this->resource->relationLoaded('user') && $this->resource->user !== null) {
            $data['user'] = new UserResource($this->resource->user);
        }

        return $data;
    }
}
