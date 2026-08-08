<?php

namespace App\Filament\Resources\StoreResource\Api\Transformers;

use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Http\Resources\UrlResource;
use App\Http\Resources\UserResource;
use App\Models\Store;
use App\Services\Helpers\LocaleHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Store $resource
 */
class StoreTransformer extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     *
     * @SuppressWarnings("UnusedFormalParameter")
     *
     * @return array
     */
    public function toArray($request)
    {
        $data = $this->resource->toArray();

        // Resolved accessor values (ISO 4217 code + BCP-47 tag) rather than the raw
        // `settings.locale_settings` blob, which is absent on most stores. Both accessors read
        // from `settings`, so PaginationHandler forces that column into any sparse-fieldset
        // select — without it they would silently report the app-level fallback instead of
        // this store's override.
        $data['currency'] = $this->resource->currency;
        $data['locale'] = LocaleHelper::toBcp47($this->resource->locale);

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
