<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Enums\ScraperService;
use App\Exceptions\AiProviderException;
use App\Filament\Resources\StoreResource;
use App\Models\Store;
use App\Services\AiConfigHealer;
use App\Services\AiExtractionService;
use App\Services\Helpers\IntegrationHelper;
use App\Services\ScrapeUrl;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    /** @var array<string, mixed>|null */
    public ?array $testScrapeResult = null;

    /** @var array<string, mixed>|null */
    public ?array $testAiResult = null;

    public ?string $testUrl = null;

    public ?string $testScraper = null;

    /** @var array<string, mixed>|null */
    public ?array $healPreview = null;

    public function runScrape(string $url, ?string $scraper = null): void
    {
        $this->authorizeAccess();

        $store = $this->buildUnsavedStore();

        if (filled($scraper)) {
            $store->settings = array_merge((array) $store->settings, ['scraper_service' => $scraper]);
        }

        $this->testUrl = $url;
        $this->testScraper = $store->scraper_service;

        $scrape = ScrapeUrl::new($url)->scrape([
            'store' => $store,
            'use_cache' => false,
        ]);

        $this->testScrapeResult = $scrape;
        $this->testAiResult = null;
    }

    public function compareWithAi(): void
    {
        $this->authorizeAccess();

        $body = data_get($this->testScrapeResult, 'body');

        if (blank($body)) {
            Notification::make()->title('No scraped HTML to analyse')->warning()->send();

            return;
        }

        $provider = IntegrationHelper::getAiProvider($this->buildUnsavedStore()->ai_provider_id);

        if ($provider === null) {
            Notification::make()->title('No AI provider configured')->warning()->send();

            return;
        }

        try {
            $result = AiExtractionService::new()->extract((string) $body, provider: $provider);
        } catch (AiProviderException) {
            Notification::make()
                ->title('AI provider error')
                ->body('Check the AI provider settings and logs.')
                ->danger()
                ->send();

            return;
        }

        if ($result === null) {
            Notification::make()->title('AI found no data in the page')->warning()->send();

            return;
        }

        $this->testAiResult = [
            'title' => $result->title,
            'description' => $result->description,
            'price' => $result->price,
            'currency' => $result->currency,
            'image' => $result->image,
            'availability' => $result->stockStatus?->getLabel(),
            'confidence' => $result->confidence,
        ];
    }

    public function previewSelfHeal(?string $url = null): void
    {
        $this->authorizeAccess();

        // Default to the URL that was actually scraped (set by runScrape), so a heal
        // triggered after clicking a product shortcut works even though that path does
        // not fill the test_url form field.
        $url = filled($url) ? $url : (string) $this->testUrl;

        if (blank($url)) {
            Notification::make()->title('Run a scrape first')->warning()->send();

            return;
        }

        $preview = AiConfigHealer::new()->previewForUrl(
            $url,
            $this->buildUnsavedStore(),
            data_get($this->testScrapeResult, 'body'),
        );

        if ($preview === null) {
            Notification::make()->title('AI could not build a working config for this URL')->warning()->send();

            return;
        }

        $this->healPreview = $preview;
    }

    public function applySelfHeal(): void
    {
        $this->authorizeAccess();

        if (blank($this->healPreview)) {
            return;
        }

        foreach (data_get($this->healPreview, 'fields', []) as $field => $slot) {
            data_set($this->data, 'scrape_strategy.'.$field, $slot);
        }

        if (data_get($this->healPreview, 'usedBrowser')) {
            data_set($this->data, 'settings.scraper_service', ScraperService::Api->value);
        }

        $this->healPreview = null;

        Notification::make()->title('Applied to the form — review the fields and Save')->success()->send();
    }

    public function discardSelfHeal(): void
    {
        $this->authorizeAccess();

        $this->healPreview = null;
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
                ->modalDescription(fn (): string => 'Dry run the current store settings'
                    .(IntegrationHelper::isAiEnabled() ? ' and compare with AI' : ''))
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->mountUsing(function (EditStore $livewire): void {
                    $livewire->testScrapeResult = null;
                    $livewire->testAiResult = null;
                    $livewire->testUrl = null;
                    $livewire->testScraper = null;
                    $livewire->healPreview = null;
                })
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
