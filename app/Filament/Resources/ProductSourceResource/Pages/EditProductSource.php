<?php

namespace App\Filament\Resources\ProductSourceResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductSource extends EditRecord
{
    protected static string $resource = ProductSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BaseAction::make('search')->icon(Icons::Search->value)
                ->resourceName('product-sources')
                ->resourceUrl('search', $this->record)
                ->label(__('Search')),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('search', ['record' => $this->record]);
    }
}
