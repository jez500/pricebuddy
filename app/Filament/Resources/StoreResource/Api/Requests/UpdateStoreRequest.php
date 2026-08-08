<?php

namespace App\Filament\Resources\StoreResource\Api\Requests;

use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Rules\ScrapeStrategyValue;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
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
            'settings.scraper_service' => 'sometimes|in:'.implode(',', ScraperService::values()),
            'settings.scraper_service_settings' => 'nullable|string',
            'settings.locale_settings.locale' => 'sometimes|string',
            'settings.locale_settings.currency' => 'sometimes|string',
            'scrape_strategy' => 'sometimes|array',
            // The value rules are conditional on the type: schema_org takes no value,
            // everything else requires one. Shared with meta-extraction so a strategy that
            // tests successfully can always be saved. See ScrapeStrategyValue.
            'scrape_strategy.image.type' => 'sometimes|in:'.implode(',', ScraperStrategyType::values()),
            'scrape_strategy.image.value' => [new ScrapeStrategyValue],
            'scrape_strategy.price.type' => 'sometimes|in:'.implode(',', ScraperStrategyType::values()),
            'scrape_strategy.price.value' => [new ScrapeStrategyValue],
            'scrape_strategy.title.type' => 'sometimes|in:'.implode(',', ScraperStrategyType::values()),
            'scrape_strategy.title.value' => [new ScrapeStrategyValue],
            'notes' => 'sometimes|string',
            'user_id' => 'sometimes|exists:users,id|in:'.auth()->id(),
        ];
    }
}
