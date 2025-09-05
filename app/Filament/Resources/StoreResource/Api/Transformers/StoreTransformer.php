<?php

namespace App\Filament\Resources\StoreResource\Api\Transformers;

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
     * @return array
     */
    public function toArray($request)
    {
        $data = $this->resource->toArray();

        // Include relationships if they are loaded
        if ($this->resource->relationLoaded('user')) {
            $data['user'] = $this->resource->user->toArray();
        }

        if ($this->resource->relationLoaded('urls')) {
            $data['urls'] = $this->resource->urls->toArray();
        }

        if ($this->resource->relationLoaded('products')) {
            $data['products'] = $this->resource->products->toArray();
        }

        return $data;
    }
}
