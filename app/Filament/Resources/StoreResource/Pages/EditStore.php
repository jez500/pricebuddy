<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Models\Store;
use App\Services\ScrapeUrl;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    /** @var array<string, mixed>|null */
    public ?array $testScrapeResult = null;

    public function runScrape(string $url): void
    {
        $this->authorizeAccess();

        $scrape = ScrapeUrl::new($url)->scrape([
            'store' => $this->buildUnsavedStore(),
            'use_cache' => false,
        ]);

        $this->testScrapeResult = $scrape;
    }

    /**
     * Build a non-persisted Store from the current edit-form state so the test
     * modal scrapes (and renders results) against unsaved config. Public because
     * StoreResource::testForm()'s results view reads it via its viewData closure.
     */
    public function buildUnsavedStore(): Store
    {
        /** @var Store $store */
        $store = $this->getRecord()->replicate();
        $store->forceFill($this->form->getRawState());

        return $store;
    }

    protected function getHeaderActions(): array
    {
        return [
            StoreResource\Actions\ShareStoreAction::make(),
            Actions\Action::make('test')
                ->label('Test')->color('gray')
                ->icon('heroicon-o-rocket-launch')
                ->modalHeading('Test store')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth(MaxWidth::ThreeExtraLarge)
                ->mountUsing(fn (EditStore $livewire) => $livewire->testScrapeResult = null)
                ->form(function (Form $form): Form {
                    /** @var Store $store */
                    $store = $this->getRecord();

                    return StoreResource::testForm($form, $store);
                }),
            Actions\DeleteAction::make()->icon('heroicon-o-trash'),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }
}
