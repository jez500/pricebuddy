<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Store $resource
 */
class StoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'initials' => $this->resource->initials,
            'domains' => $this->resource->domains,
            // Exposed as `scrape_settings` in the API; the model attribute is `scrape_strategy`.
            // The name differs intentionally to keep the public contract stable.
            'scrape_settings' => $this->resource->scrape_strategy,
            'settings' => $this->resource->settings,
        ];
    }
}
