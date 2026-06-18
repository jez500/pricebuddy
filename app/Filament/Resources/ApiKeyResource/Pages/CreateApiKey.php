<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateApiKey extends CreateRecord
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['permission_mode'] ?? 'all') === 'custom' && $this->selectedAbilities($data) === []) {
            throw ValidationException::withMessages([
                'data.permission_mode' => 'Select at least one permission, or choose All.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        $abilities = ($data['permission_mode'] ?? 'all') === 'all'
            ? ['*']
            : $this->selectedAbilities($data);

        $newToken = $user->createToken($data['name'], $abilities);

        session()->flash('api-key.plain_text', $newToken->plainTextToken);
        session()->flash('api-key.name', $data['name']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function selectedAbilities(array $data): array
    {
        return collect($data['abilities'] ?? [])
            ->flatten()
            ->filter()
            ->unique()
            ->intersect(ApiKeyResource::knownAbilities())
            ->values()
            ->all();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return ApiKeyResource::getUrl('created');
    }
}
