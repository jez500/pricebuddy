<?php

namespace App\Filament\Resources\ProductSourceResource\Pages;

use App\Enums\Icons;
use App\Filament\Resources\ProductSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductSources extends ListRecords
{
    protected static string $resource = ProductSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->color('gray')
                ->icon(Icons::Add->value),
        ];
    }
}
