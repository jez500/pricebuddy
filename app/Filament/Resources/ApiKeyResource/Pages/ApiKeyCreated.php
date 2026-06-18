<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Resources\Pages\Page;

class ApiKeyCreated extends Page
{
    protected static string $resource = ApiKeyResource::class;

    protected static string $view = 'filament.resources.api-key-resource.pages.api-key-created';

    public ?string $plainTextToken = null;

    public ?string $keyName = null;

    public function mount(): void
    {
        $this->plainTextToken = session()->pull('api-key.plain_text');
        $this->keyName = session()->pull('api-key.name');

        if (blank($this->plainTextToken)) {
            $this->redirect(ApiKeyResource::getUrl('index'));
        }
    }
}
