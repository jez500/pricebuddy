<?php

namespace App\Notifications;

use App\Models\Product;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AddUrlFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public Product $product, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->getKey(),
            'url' => $this->url,
        ];
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title('Unable to add URL to '.Str::limit($this->product->title, 60))
            ->body(Str::limit($this->url, 200).' has no price, or no store matches it.')
            ->status('warning')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->product->view_url, PHP_URL_PATH))
                    ->label('View product'),
            ])
            ->getDatabaseMessage();
    }
}
