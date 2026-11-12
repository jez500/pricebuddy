<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Models\Product;
use App\Models\Url;
use App\Notifications\PriceAlertNotification;
use App\Notifications\StockAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends a sample alert for the product to the current user through their enabled notification
 * methods, so notification settings can be verified without waiting for a real price change.
 */
class TestNotificationAction extends Action
{
    public const string TYPE_PRICE = 'price';

    public const string TYPE_STOCK = 'stock';

    public static function getDefaultName(): ?string
    {
        return 'test_notification';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Test notification'));

        $this->color('gray');

        $this->icon('heroicon-o-bell-alert');

        $this->modalHeading(__('Send a test notification'));

        $this->modalDescription(__('Sends a sample alert for this product to you, using the notification methods you have enabled. No price or stock status is changed.'));

        $this->modalSubmitActionLabel(__('Send'));

        $this->form([
            Radio::make('type')
                ->label(__('Alert type'))
                ->options([
                    self::TYPE_PRICE => __('Price alert'),
                    self::TYPE_STOCK => __('Back in stock alert'),
                ])
                ->default(self::TYPE_PRICE)
                ->required(),
        ]);

        $this->action(function (array $data): void {
            /** @var Product $product */
            $product = $this->getRecord();
            $user = auth()->user();

            /** @var ?Url $url */
            $url = $product->urls()->first();

            if (! $url) {
                Notification::make()
                    ->title(__('Add a URL to this product to send a test notification'))
                    ->warning()
                    ->send();

                return;
            }

            $notification = ($data['type'] ?? self::TYPE_PRICE) === self::TYPE_STOCK
                ? (new StockAlertNotification($url))->asTest()
                : (new PriceAlertNotification($url))->asTest();

            $results = NotificationsHelper::sendTest($user, $notification);

            if ($results === []) {
                Notification::make()
                    ->title(__('No notification methods to test'))
                    ->body(__('Enable a notification method in your account settings, it must also be configured in the app settings.'))
                    ->warning()
                    ->send();

                return;
            }

            $sent = collect($results)->filter(fn (?string $error) => is_null($error))->keys();
            $failed = collect($results)->filter(fn (?string $error) => ! is_null($error));

            if ($sent->isNotEmpty()) {
                Notification::make()
                    ->title(__('Test notification sent'))
                    ->body(__('Sent via: :methods', ['methods' => $sent->map(fn (string $name) => $this->methodLabel($name))->implode(', ')]))
                    ->success()
                    ->send();
            }

            if ($failed->isNotEmpty()) {
                $failed->each(fn (string $error, string $name) => Log::channel('db')->error(
                    sprintf('Test notification failed for %s: %s', $name, $error)
                ));

                // Error messages can include service URLs and tokens set by an admin, so only admins see them.
                Notification::make()
                    ->title(__('Test notification failed'))
                    ->body($failed->map(fn (string $error, string $name) => $this->methodLabel($name).': '.
                        ($user->isAdmin() ? $error : __('Ask an administrator to check the settings and logs')))->implode("\n"))
                    ->danger()
                    ->persistent()
                    ->send();
            }
        });
    }

    protected function methodLabel(string $name): string
    {
        return Str::headline($name);
    }
}
