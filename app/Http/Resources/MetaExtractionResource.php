<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\LocaleHelper;
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
        $store = $store instanceof Store ? $store : null;

        return [
            'title' => data_get($this->resource, 'title'),
            'price' => data_get($this->resource, 'price'),
            // Sit alongside `price` so a client never has to reach into `store` to format it;
            // `store` is `{}` when none could be resolved, hence the app-level fallback here.
            // `?:` also covers a store whose locale_settings hold an explicit null/empty value,
            // which data_get() returns as-is rather than falling back to its default.
            'currency' => $store?->currency ?: CurrencyHelper::getCurrency(),
            'locale' => LocaleHelper::toBcp47($store?->locale ?: CurrencyHelper::getLocale()),
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
