<?php

namespace App\Filament\Resources\StoreResource\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:stores,slug,'.$this->route('id'),
            'initials' => 'sometimes|string|max:2',
            'domains' => 'sometimes|array',
            'domains.*.domain' => 'required_with:domains|string',
            'settings' => 'sometimes|array',
            'settings.scraper_service' => 'sometimes|in:'.implode(',', \App\Enums\ScraperService::values()),
            'settings.scraper_service_settings' => 'nullable|string',
            'settings.locale_settings.locale' => 'sometimes|string',
            'settings.locale_settings.currency' => 'sometimes|string',
            'scrape_strategy' => 'sometimes|array',
            'scrape_strategy.image.type' => 'sometimes|in:'.implode(',', \App\Enums\ScraperStrategyType::values()),
            'scrape_strategy.image.value' => 'required_with:scrape_strategy.image.type|string',
            'scrape_strategy.price.type' => 'sometimes|in:'.implode(',', \App\Enums\ScraperStrategyType::values()),
            'scrape_strategy.price.value' => 'required_with:scrape_strategy.price.type|string',
            'scrape_strategy.title.type' => 'sometimes|in:'.implode(',', \App\Enums\ScraperStrategyType::values()),
            'scrape_strategy.title.value' => 'required_with:scrape_strategy.title.type|string',
            'notes' => 'sometimes|string',
            'user_id' => 'sometimes|exists:users,id|in:'.auth()->id(),
        ];
    }
}
