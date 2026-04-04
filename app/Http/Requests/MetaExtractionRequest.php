<?php

namespace App\Http\Requests;

use App\Enums\ScraperService;
use App\Enums\ScraperStrategyType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MetaExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url'],
            'store' => ['sometimes', 'array'],
            'store.name' => ['sometimes', 'string', 'max:255'],
            'store.domains' => ['sometimes', 'array'],
            'store.domains.*.domain' => ['required_with:store.domains', 'string'],
            'store.settings' => ['sometimes', 'array'],
            'store.settings.scraper_service' => ['sometimes', 'in:'.implode(',', ScraperService::values())],
            'store.settings.scraper_service_settings' => ['nullable', 'string'],
            'store.settings.locale_settings.locale' => ['sometimes', 'string'],
            'store.settings.locale_settings.currency' => ['sometimes', 'string'],
            'store.settings.cookies' => ['nullable', 'string'],
            'store.scrape_strategy' => ['sometimes', 'array'],
            'store.scrape_strategy.title' => ['sometimes', 'array'],
            'store.scrape_strategy.title.type' => ['sometimes', 'in:'.implode(',', ScraperStrategyType::values())],
            'store.scrape_strategy.title.value' => $this->scrapeStrategyValueRules('title'),
            'store.scrape_strategy.title.prepend' => ['nullable', 'string'],
            'store.scrape_strategy.title.append' => ['nullable', 'string'],
            'store.scrape_strategy.price' => ['sometimes', 'array'],
            'store.scrape_strategy.price.type' => ['sometimes', 'in:'.implode(',', ScraperStrategyType::values())],
            'store.scrape_strategy.price.value' => $this->scrapeStrategyValueRules('price'),
            'store.scrape_strategy.price.prepend' => ['nullable', 'string'],
            'store.scrape_strategy.price.append' => ['nullable', 'string'],
            'store.scrape_strategy.image' => ['sometimes', 'array'],
            'store.scrape_strategy.image.type' => ['sometimes', 'in:'.implode(',', ScraperStrategyType::values())],
            'store.scrape_strategy.image.value' => $this->scrapeStrategyValueRules('image'),
            'store.scrape_strategy.image.prepend' => ['nullable', 'string'],
            'store.scrape_strategy.image.append' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function scrapeStrategyValueRules(string $field): array
    {
        return [
            Rule::requiredIf(function () use ($field): bool {
                $type = data_get($this->input(), "store.scrape_strategy.{$field}.type");

                return filled($type) && $type !== ScraperStrategyType::SchemaOrg->value;
            }),
            function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                $type = data_get($this->input(), "store.scrape_strategy.{$field}.type");

                if ($type === ScraperStrategyType::SchemaOrg->value) {
                    if (! is_null($value)) {
                        $fail("The {$attribute} field must be null when using schema_org.");
                    }

                    return;
                }

                if (! is_string($value) || trim($value) === '') {
                    $fail("The {$attribute} field must be a non-empty string.");
                }
            },
        ];
    }
}
