<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Enums\Icons;
use App\Models\Product;
use App\Models\Url;
use App\Rules\StoreUrl;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

class AddUrlAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'add_url';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Add URL'));

        $this->successNotificationTitle(__('URL Added'));

        $this->failureNotificationTitle(__('Unable to add URL'));

        $this->modalHeading(__('Add URL to this product'));

        $this->icon(Icons::Add->value);

        $this->form([
            TextInput::make('url')
                ->hiddenLabel(true)
                ->placeholder('http://my-store.com/product')
                ->rules([new StoreUrl]),
            TextInput::make('price_factor')
                ->label(__('Price Factor'))
                ->numeric()
                ->default(1)
                ->minValue(0.01)
                ->helperText(__('Number of items (unit price = price / factor)')),
        ]);

        $this->color('gray');

        $this->keyBindings(['mod+a']);

        $this->action(function (array $data, Product $record): void {

            /** @var Product $product */
            $product = $this->record;

            try {
                $urlModel = Url::createFromUrl(
                    url: $data['url'],
                    productId: $product->getKey(),
                    userId: auth()->id(),
                    priceFactor: (float) ($data['price_factor'] ?? 1),
                );

                if ($urlModel === false) {
                    $this->failure();

                    return;
                }

                $this->success();
                $this->redirect($product->view_url);
            } catch (Exception $e) {
                $this->failure();
            }
        });
    }
}
