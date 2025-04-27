<?php

namespace App\Filament\Resources\StoreResource\Actions;

use App\Enums\Icons;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\StaticAction;
use Illuminate\Contracts\View\View;

class ShareStoreAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'share_store';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Share'));

        $this->successNotificationTitle(__('Done'));

        $this->failureNotificationTitle(__('Error'));

        $this->modalHeading(__('Share this store'));

        $this->icon(Icons::Share->value);

        $this->modalContent(fn (Store $record): View => view(
            'filament.resources.store-resource.actions.share-store',
            ['record' => $record],
        ));

        $this->color('gray');

        $this->keyBindings(['mod+s']);
    }

    public function getModalSubmitAction(): ?StaticAction
    {
        return null;
    }

    public function getModalCancelAction(): ?StaticAction
    {
        return null;
    }
}
