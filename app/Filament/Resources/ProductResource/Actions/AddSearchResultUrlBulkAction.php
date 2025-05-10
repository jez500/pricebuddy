<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Actions\CreateProductAction;
use App\Enums\Icons;
use App\Models\Product;
use App\Models\Url;
use App\Models\UrlResearch;
use Exception;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class AddSearchResultUrlBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    protected ?Product $product = null;

    protected ?string $searchQuery = null;

    public static function getDefaultName(): ?string
    {
        return 'add_urls';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('New product with selected urls'));

        $this->icon(Icons::Add->value);

        $this->color('primary');

        $this->action(function (): void {
            $searchQuery = $this->searchQuery;
            $product = $this->product;

            $this->process(static function (Collection $records) use ($searchQuery, &$product) {
                $image = null;

                if ($records->isEmpty()) {
                    return;
                }

                if (! $product) {
                    try {
                        /** @var Product $product */
                        $product = call_user_func(new CreateProductAction, [
                            'title' => $searchQuery,
                        ]);
                    } catch (Exception $e) {
                        logger()->error('Failed to create product from search: '.$e->getMessage(), [
                            'search_query' => $searchQuery,
                        ]);

                        return;
                    }
                }

                /** @phpstan-ignore-next-line */
                $records->each(function (UrlResearch $result) use ($product, &$image) {
                    try {
                        // Create url and pass the product id and user id.
                        $url = Url::createFromUrl($result->url, $product->getKey(), auth()->id(), true);

                        if ($url) {
                            // Set the product image to the first url that has an image.
                            $resultImage = data_get($result->toArray(), 'image');
                            if (! empty($resultImage) && empty($image)) {
                                $image = $resultImage;
                            }
                        }
                    } catch (Exception $e) {
                        logger()->warning('Failed to create URL: '.$e->getMessage(), [
                            'url' => $result->url,
                            'product_id' => $product->getKey(),
                        ]);
                    }
                });

                if ($image && empty($product->image)) {
                    $product->update(['image' => $image]);
                }
            });

            if ($product) {
                $this->redirect($product->view_url);
            }

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();
    }

    public function withSearchQuery(string $searchQuery): self
    {
        $this->searchQuery = $searchQuery;

        return $this;
    }

    public function withProduct(?Product $product): self
    {
        $this->product = $product;

        if ($product) {
            $this->label(__('Add selected urls to this product'));
        }

        return $this;
    }
}
