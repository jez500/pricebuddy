<?php

namespace App\Filament\Resources\ProductSourceResource\Pages;

use App\Filament\Resources\ProductSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductSource extends CreateRecord
{
    protected static string $resource = ProductSourceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('search', ['record' => $this->record]);
    }
}
