<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 */
class MetaExtractionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = data_get($this->resource, 'store');

        return [
            'title' => data_get($this->resource, 'title'),
            'price' => data_get($this->resource, 'price'),
            'image' => data_get($this->resource, 'image'),
            'description' => data_get($this->resource, 'description'),
            'availability' => data_get($this->resource, 'availability'),
            /** @var array<string, StoreResource> */
            // Empty object (serialises to `{}`, not `null`) keeps the `store` field shape stable
            // for API clients even when no store could be resolved or detected.
            'store' => $store ? new StoreResource($store) : (object) [],
        ];
    }
}
