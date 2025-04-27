<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\Traits\TestAfterEdit;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    use TestAfterEdit;

    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StoreResource\Actions\ShareStoreAction::make(),
            Actions\Action::make('test')
                ->url(fn () => StoreResource::getUrl('test', ['record' => $this->record]))
                ->label('Test')->color('gray')
                ->icon('heroicon-o-rocket-launch'),
            Actions\DeleteAction::make()->icon('heroicon-o-trash'),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getSaveAndTestAction(__('Save & test')),
        ];
    }

    public function saveAndTest(): void
    {
        $this->testAfterCreate = true;
        $this->save();
    }
}
