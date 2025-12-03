<?php

namespace App\Filament\Resources\ProductSourceResource\Pages;

use App\Filament\Resources\ProductSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductSource extends EditRecord
{
    protected static string $resource = ProductSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
