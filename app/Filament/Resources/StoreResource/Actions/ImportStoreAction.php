<?php

namespace App\Filament\Resources\StoreResource\Actions;

use App\Actions\CreateStoreAction;
use App\Enums\Icons;
use App\Filament\Resources\StoreResource;
use App\Rules\ImportStore;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class ImportStoreAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'import_store';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Import'));

        $this->successNotificationTitle(__('Store created'));

        $this->failureNotificationTitle(__('Unable to create store'));

        $this->modalHeading(__('Import store from export'));

        $this->icon(Icons::Import->value);

        $this->form([
            Textarea::make('json')
                ->label('JSON to import')
                ->rows(15)
                ->extraInputAttributes(['class' => 'font-mono text-sm'])
                ->rules([new ImportStore]),
            Toggle::make('edit_after_save')
                ->label('Edit store after saving')
                ->default(false),
        ]);

        $this->color('gray');

        $this->keyBindings(['mod+i']);

        $this->action(function (array $data): void {
            $attributes = json_decode(data_get($data, 'json'), true);
            $editAfterSave = data_get($data, 'edit_after_save', false);

            if ($store = (new CreateStoreAction)($attributes)) {
                $this->success();

                if ($editAfterSave) {
                    $this->redirect(StoreResource::getUrl('edit', ['record' => $store]));
                } else {
                    $this->redirect(StoreResource::getUrl('index'));
                }
            } else {
                $this->failure();
            }
        });
    }
}
