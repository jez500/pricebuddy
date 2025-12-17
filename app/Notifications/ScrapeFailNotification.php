<?php

namespace App\Notifications;

use App\Filament\Resources\LogMessageResource;
use App\Models\Product;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ScrapeFailNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Product $product)
    {
        //
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->product->toArray();
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title('Error scraping product urls')
            ->body(Str::limit($this->product->title, 100))
            ->status('warning')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->product->view_url, PHP_URL_PATH))
                    ->label('View product'),
                Action::make('logs')
                    ->url(parse_url(LogMessageResource::getUrl('index'), PHP_URL_PATH))
                    ->label('View logs'),
            ])
            ->getDatabaseMessage();
    }
}
