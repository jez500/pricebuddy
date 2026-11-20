<?php

namespace App\Filament\Resources\StoreResource\Api\Requests;

use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use App\Enums\VariantSourceType;
use App\Rules\ScrapeStrategyValue;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
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
        ], self::getStrategyRules(), self::getVariantStrategyRules());
    }

    /**
     * Optional variants strategy: where the store lists product variants (sizes,
     * colours, ...). Only used by URLs that track a variant.
     */
    public static function getVariantStrategyRules(): array
    {
        return [
            'scrape_strategy.variants' => 'nullable|array',
            'scrape_strategy.variants.type' => 'required_with:scrape_strategy.variants|in:'.implode(',', VariantSourceType::values()),
            'scrape_strategy.variants.value' => 'nullable|string|required_if:scrape_strategy.variants.type,selector,regex,dom',
            'scrape_strategy.variants.path' => 'nullable|string',
            'scrape_strategy.variants.options' => 'nullable|array',
            'scrape_strategy.variants.options.*' => 'string',
            'scrape_strategy.variants.availability' => 'nullable|string',
            'scrape_strategy.variants.out_of_stock' => 'nullable|string',
            'scrape_strategy.variants.price' => 'nullable|string',
            'scrape_strategy.variants.id' => 'nullable|string',
            'scrape_strategy.variants.price_divisor' => 'nullable|numeric|gt:0',
        ];
    }

    public static function getStrategyRules(): array
    {
        return collect(['image', 'price', 'title'])
            ->mapWithKeys(fn ($strategy) => [
                "scrape_strategy.{$strategy}.type" => 'required|in:'.implode(',', ScraperStrategyType::values()),
                // Conditional on the type: schema_org takes no value, everything else
                // requires one. Shared with meta-extraction so a strategy that tests
                // successfully can always be saved. See ScrapeStrategyValue.
                "scrape_strategy.{$strategy}.value" => [new ScrapeStrategyValue],
                "scrape_strategy.{$strategy}.prepend" => 'nullable|string',
                "scrape_strategy.{$strategy}.append" => 'nullable|string',
            ])
            ->toArray();
    }
}
