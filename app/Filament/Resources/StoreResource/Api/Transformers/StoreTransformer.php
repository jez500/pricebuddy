<?php

namespace App\Filament\Resources\StoreResource\Api\Transformers;

use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Http\Resources\UrlResource;
use App\Http\Resources\UserResource;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
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
        //
        // `?:` covers a store whose locale_settings hold an explicit null/empty value: the
        // accessors use data_get(), which only falls back when the key is absent and returns
        // null/'' as-is when it is present but empty. Without this, clients would receive
        // `currency: null` and `locale: ""`, which Intl.NumberFormat rejects.
        $data['currency'] = $this->resource->currency ?: CurrencyHelper::getCurrency();
        $data['locale'] = LocaleHelper::toBcp47($this->resource->locale ?: CurrencyHelper::getLocale());

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
