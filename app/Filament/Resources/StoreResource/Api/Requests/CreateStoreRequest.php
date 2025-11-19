<?php

namespace App\Filament\Resources\StoreResource\Api\Requests;

use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use Illuminate\Foundation\Http\FormRequest;

class CreateStoreRequest extends FormRequest
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
        return self::getAllRules();
    }

    public static function getAllRules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:stores,slug',
            'initials' => 'nullable|string|max:2',
            'domains' => 'required|array',
            'domains.*.domain' => 'required|string',
            'settings' => 'required|array',
            'settings.scraper_service' => 'required|in:'.implode(',', ScraperService::values()),
            'settings.scraper_service_settings' => 'nullable|string',
            'settings.locale_settings.locale' => 'nullable|string',
            'settings.locale_settings.currency' => 'nullable|string',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id|in:'.auth()->id(),
        ], self::getStrategyRules());
    }

    public static function getStrategyRules(): array
    {
        return collect(['image', 'price', 'title'])
            ->mapWithKeys(fn ($strategy) => [
                "scrape_strategy.{$strategy}.type" => 'required|in:'.implode(',', ScraperStrategyType::values()),
                "scrape_strategy.{$strategy}.value" => 'required|string',
                "scrape_strategy.{$strategy}.prepend" => 'nullable|string',
                "scrape_strategy.{$strategy}.append" => 'nullable|string',
            ])
            ->toArray();
    }
}
