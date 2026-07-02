<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Tag;
use App\Models\Url;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
            priceFactor: (float) data_get($data, 'price_factor', 1),
        );

        if ($urlModel === false) {
            throw ValidationException::withMessages([
                'url' => __('Unable to create product from this URL'),
            ]);
        }

        $product = $urlModel->product;

        // Scope submitted tag IDs to the current user's own tags: the select only
        // offers the user's tags, but a tampered request could submit arbitrary IDs.
        $tagIds = Tag::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', (array) data_get($data, 'tags', []))
            ->pluck('id')
            ->all();

        if ($tagIds !== []) {
            $product->tags()->syncWithoutDetaching($tagIds);
        }

        return $product;
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
