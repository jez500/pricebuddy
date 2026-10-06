<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Actions\AddUrlsToProductAction;
use App\Enums\Icons;
use App\Models\Product;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Add several URLs to a product at once (#206). The URLs are scraped in the background.
 */
class AddUrlsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'add_multiple_urls';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Add multiple URLs'));

        $this->modalHeading(__('Add multiple URLs to this product'));

        $this->icon(Icons::Add->value);

        $this->color('gray');

        $this->form([
            Textarea::make('urls')
                ->label(__('URLs'))
                ->placeholder("https://my-store.com/product\nhttps://other-store.com/product")
                ->helperText(__('One URL per line, up to :max. The URLs are checked in the background. You get a notification for each URL that cannot be added.', ['max' => AddUrlsToProductAction::MAX_URLS]))
                ->rows(10)
                ->required()
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (count(AddUrlsToProductAction::parse((string) $value)) > AddUrlsToProductAction::MAX_URLS) {
                            $fail(__('Add no more than :max URLs at once.', ['max' => AddUrlsToProductAction::MAX_URLS]));
                        }
                    },
                ]),
            TextInput::make('price_factor')
                ->label(__('Price Factor'))
                ->numeric()
                ->default(1)
                ->minValue(0.01)
                ->helperText(__('Number of items (unit price = price / factor). Used for every URL in the list.')),
        ]);

        $this->action(function (array $data): void {
            /** @var Product $product */
            $product = $this->getRecord();

            $result = (new AddUrlsToProductAction)(
                $product,
                (string) $data['urls'],
                (int) auth()->id(),
                (float) ($data['price_factor'] ?? 1),
            );

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
        });
    }
}
