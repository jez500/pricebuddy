<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Actions\AddUrlsToProductAction;
use App\Enums\Icons;
use App\Models\Product;
use App\Models\Url;
use App\Rules\StoreUrl;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Add one or many URLs to a product. One URL is added straight away. Many URLs are
 * scraped in the background (#206).
 */
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

        $this->modalHeading(__('Add one or many URL\'s to this product'));

        $this->icon(Icons::Add->value);

        $this->form([
            Textarea::make('url')
                ->hiddenLabel(true)
                ->placeholder("http://my-store.com/product\nhttp://other-store.com/product")
                ->helperText(__('One URL per line, up to :max. When you add more than one URL, they are added in the background.', ['max' => AddUrlsToProductAction::MAX_URLS]))
                ->rows(3)
                ->required()
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $urls = AddUrlsToProductAction::parse((string) $value);

                        if (count($urls) > AddUrlsToProductAction::MAX_URLS) {
                            $fail(__('Add no more than :max URLs at once.', ['max' => AddUrlsToProductAction::MAX_URLS]));
                        } elseif (count($urls) === 1) {
                            // Many URLs are checked by the queued jobs, because each check scrapes the page.
                            (new StoreUrl)->validate($attribute, $urls[0], $fail);
                        }
                    },
                ]),
            TextInput::make('price_factor')
                ->label(__('Price Factor'))
                ->numeric()
                ->default(1)
                ->minValue(0.01)
                ->helperText(__('Number of items (unit price = price / factor)')),
        ]);

        $this->color('gray');

        $this->keyBindings(['mod+a']);

        $this->action(function (array $data): void {

            /** @var Product $product */
            $product = $this->record;

            $urls = AddUrlsToProductAction::parse((string) $data['url']);
            $priceFactor = (float) ($data['price_factor'] ?? 1);

            if (count($urls) > 1) {
                $this->queueUrls($product, $urls, $priceFactor);

                return;
            }

            try {
                $urlModel = Url::createFromUrl(
                    url: $urls[0],
                    productId: $product->getKey(),
                    userId: auth()->id(),
                    priceFactor: $priceFactor,
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

    /**
     * @param  array<int, string>  $urls
     */
    protected function queueUrls(Product $product, array $urls, float $priceFactor): void
    {
        $result = (new AddUrlsToProductAction)($product, $urls, (int) auth()->id(), $priceFactor);

        $skipped = collect($result['skipped'])
            ->map(fn (array $skip) => $skip['url'].' ('.match ($skip['reason']) {
                AddUrlsToProductAction::SKIP_EXISTING => __('already on this product'),
                AddUrlsToProductAction::SKIP_DUPLICATE => __('duplicate'),
                default => __('not a valid URL'),
            }.')');

        Notification::make()
            ->title(trans_choice(':count URL is being added in the background|:count URLs are being added in the background', count($result['queued'])))
            ->body($skipped->isEmpty() ? null : __('Skipped: :urls', ['urls' => $skipped->implode(', ')]))
            ->status(empty($result['queued']) ? 'warning' : 'success')
            ->send();
    }
}
