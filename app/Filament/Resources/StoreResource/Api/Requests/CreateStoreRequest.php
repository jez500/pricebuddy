<?php

namespace App\Filament\Resources\StoreResource\Api\Requests;

use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
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
        ], self::getStrategyRules(), self::getPriceAdjustmentRules());
    }

    /**
     * The optional price adjustments and shipping strategy, shared with UpdateStoreRequest.
     * A shipping strategy cannot use schema_org.
     *
     * @return array<string, mixed>
     */
    public static function getPriceAdjustmentRules(): array
    {
        $shippingTypes = array_diff(ScraperStrategyType::values(), [ScraperStrategyType::SchemaOrg->value]);

        return [
            'settings.price_adjustments' => 'sometimes|array',
            'settings.price_adjustments.tax_included_percent' => 'nullable|numeric|min:0|max:100',
            'settings.price_adjustments.tax_percent' => 'nullable|numeric|min:0|max:100',
            'settings.price_adjustments.shipping_cost' => 'nullable|numeric|min:0',
            'settings.price_adjustments.free_shipping_from' => 'nullable|numeric|min:0',
            'settings.price_adjustments.tax_on_shipping' => 'nullable|boolean',
            'scrape_strategy.shipping.type' => 'sometimes|in:'.implode(',', $shippingTypes),
            'scrape_strategy.shipping.value' => [new ScrapeStrategyValue],
            'scrape_strategy.shipping.prepend' => 'nullable|string',
            'scrape_strategy.shipping.append' => 'nullable|string',
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
