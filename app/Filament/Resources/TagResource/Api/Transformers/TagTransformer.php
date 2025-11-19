<?php

namespace App\Filament\Resources\TagResource\Api\Transformers;

use App\Models\Tag;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Tag $resource
 */
class TagTransformer extends JsonResource
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
        if ($this->resource->relationLoaded('products')) {
            $data['products'] = $this->resource->products->toArray();
        }

        return $data;
    }
}
