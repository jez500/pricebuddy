<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Url;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $url = data_get($data, 'url');
        $productId = data_get($data, 'product_id');

        $urlModel = Url::createFromUrl(
            url: $url,
            productId: $productId,
            userId: auth()->id(),
            createStore: data_get($data, 'create_store', false),
            factor: (float) data_get($data, 'factor', 1),
        );

        return $urlModel->product;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ProductResource\Widgets\CreateViaSearchForm::class,
        ];
    }
}
