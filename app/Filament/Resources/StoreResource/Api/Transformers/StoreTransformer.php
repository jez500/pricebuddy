<?php

namespace App\Filament\Resources\StoreResource\Api\Transformers;

use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Http\Resources\UrlResource;
use App\Http\Resources\UserResource;
use App\Models\Store;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Store $resource
 */
class StoreTransformer extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @SuppressWarnings("UnusedFormalParameter")
     *
     * @return array
     */
    public function toArray($request)
    {
        $data = $this->resource->toArray();

        // Include relationships if they are loaded
        if ($this->resource->relationLoaded('user')) {
            $data['user'] = new UserResource($this->resource->user);
        }

        if ($this->resource->relationLoaded('urls')) {
            $data['urls'] = UrlResource::collection($this->resource->urls);
        }

        if ($this->resource->relationLoaded('products')) {
            $data['products'] = ProductTransformer::collection($this->whenLoaded('products'));
        }

        return $data;
    }
}
