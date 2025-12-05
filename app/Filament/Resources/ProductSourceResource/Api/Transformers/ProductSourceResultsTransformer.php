<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Transformers;

use App\Http\Resources\UserResource;
use App\Models\ProductSource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProductSourceResult
 *
 * @property array $resource
 */
class ProductSourceResultsTransformer extends JsonResource
{
    /**
     * Search for product via the product source.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request)
    {
        return [
            'title' => (string) data_get($this->resource, 'title'),
            'url' => (string) data_get($this->resource, 'url'),
            'source' => $this->when(isset($this->resource['source']), data_get($this->resource, 'source')),
            'source_id' => $this->when(isset($this->resource['source_id']), data_get($this->resource, 'source_id'))
        ];
    }
}
