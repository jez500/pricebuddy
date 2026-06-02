<?php

namespace App\Filament\Resources\NotificationHistoryResource\Pages;

use App\Filament\Resources\NotificationHistoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListNotifications extends ListRecords
{
    protected static string $resource = NotificationHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label(__('Mark all as read'))
                ->icon('heroicon-m-check')
                ->color('gray')
                ->visible(fn () => auth()->user()?->unreadNotifications()->exists())
                ->action(fn () => auth()->user()?->unreadNotifications()->update(['read_at' => now()])),
        ];
    }
}
